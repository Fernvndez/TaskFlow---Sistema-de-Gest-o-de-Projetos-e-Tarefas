<?php
// app/Services/ProjectService.php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Jobs\SendProjectNotificationJob;
use App\Notifications\ProjectCreatedNotification;
use App\Notifications\ProjectUpdatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ProjectService
{
    public function createProject(array $data): Project
    {
        return DB::transaction(function () use ($data) {
            $project = Project::create($data);

            // Add manager as project member
            $manager = User::find($data['manager_id']);
            $project->addMember($manager, 'lead');

            // Send notification to manager
            $manager->notify(new ProjectCreatedNotification($project));

            // Dispatch job to notify relevant users
            SendProjectNotificationJob::dispatch($project, 'created');

            return $project;
        });
    }

    public function updateProject(Project $project, array $data): Project
    {
        $oldData = $project->toArray();
        
        $project->update($data);

        // Check if manager changed
        if (isset($data['manager_id']) && $data['manager_id'] !== $project->getOriginal('manager_id')) {
            $newManager = User::find($data['manager_id']);
            $project->addMember($newManager, 'lead');
            
            // Remove old manager if not admin
            $oldManager = User::find($project->getOriginal('manager_id'));
            if ($oldManager && !$oldManager->is_admin) {
                $project->removeMember($oldManager);
            }
        }

        // Notify members about important changes
        $importantFields = ['status', 'due_date', 'manager_id'];
        if (array_intersect_key($data, array_flip($importantFields))) {
            SendProjectNotificationJob::dispatch($project, 'updated', $oldData);
        }

        return $project->fresh();
    }

    public function deleteProject(Project $project): void
    {
        DB::transaction(function () use ($project) {
            // Notify members before deletion
            $project->members->each(function ($member) use ($project) {
                $member->notify(new ProjectUpdatedNotification($project, 'deleted'));
            });

            $project->delete();
        });
    }

    public function addMemberToProject(Project $project, int $userId, string $role = 'member'): void
    {
        $user = User::findOrFail($userId);
        $project->addMember($user, $role);

        // Notify new member
        $user->notify(new ProjectUpdatedNotification($project, 'member_added'));
    }

    public function removeMemberFromProject(Project $project, int $userId): void
    {
        $user = User::findOrFail($userId);
        $project->removeMember($user);

        // Notify removed member
        $user->notify(new ProjectUpdatedNotification($project, 'member_removed'));
    }

    public function getProjectMetrics(Project $project): array
    {
        $tasksByStatus = $project->getTasksByStatus();
        $totalTasks = array_sum($tasksByStatus);

        $overdueTasks = $project->tasks()->overdue()->count();
        $completedThisWeek = $project->tasks()
            ->where('status', 'done')
            ->where('completed_at', '>=', now()->startOfWeek())
            ->count();

        return [
            'total_tasks' => $totalTasks,
            'tasks_by_status' => $tasksByStatus,
            'progress_percentage' => $project->progress,
            'overdue_tasks' => $overdueTasks,
            'completed_this_week' => $completedThisWeek,
            'members_count' => $project->members()->count(),
            'days_remaining' => $project->days_remaining,
            'is_overdue' => $project->is_overdue,
        ];
    }
}

// app/Services/TaskService.php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Models\TaskComment;
use App\Jobs\SendTaskNotificationJob;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function createTask(array $data): Task
    {
        return DB::transaction(function () use ($data) {
            $data['created_by'] = auth()->id();
            $task = Task::create($data);

            // Notify assigned user
            if ($task->assigned_to) {
                $assignedUser = User::find($task->assigned_to);
                $assignedUser->notify(new TaskAssignedNotification($task));
            }

            // Dispatch job for additional notifications
            SendTaskNotificationJob::dispatch($task, 'created');

            return $task;
        });
    }

    public function updateTask(Task $task, array $data): Task
    {
        $oldAssignedTo = $task->assigned_to;
        $oldStatus = $task->status;

        $task->update($data);

        // Check if assignment changed
        if (isset($data['assigned_to']) && $data['assigned_to'] !== $oldAssignedTo) {
            if ($task->assigned_to) {
                $newAssignee = User::find($task->assigned_to);
                $newAssignee->notify(new TaskAssignedNotification($task));
            }
        }

        // Check if status changed
        if (isset($data['status']) && $data['status'] !== $oldStatus) {
            // Mark as completed if status is done
            if ($data['status'] === 'done' && !$task->completed_at) {
                $task->update(['completed_at' => now()]);
            }

            // Notify relevant users about status change
            SendTaskNotificationJob::dispatch($task, 'status_changed', ['old_status' => $oldStatus]);
        }

        return $task->fresh();
    }

    public function updateTaskStatus(Task $task, string $status): Task
    {
        $oldStatus = $task->status;
        
        $updateData = ['status' => $status];
        if ($status === 'done') {
            $updateData['completed_at'] = now();
        } elseif ($status === 'in-progress' && $task->status === 'todo') {
            // Task started
            $updateData['started_at'] = now();
        }

        $task->update($updateData);

        // Notify about status change
        if ($task->assignedUser) {
            $task->assignedUser->notify(new TaskStatusChangedNotification($task, $oldStatus));
        }

        return $task;
    }

    public function deleteTask(Task $task): void
    {
        DB::transaction(function () use ($task) {
            // Notify assigned user if exists
            if ($task->assignedUser) {
                // Could send a task deleted notification
            }

            $task->delete();
        });
    }

    public function addCommentToTask(Task $task, User $user, string $content, array $attachments = []): TaskComment
    {
        $comment = $task->addComment($user, $content, $attachments);

        // Notify task assignee and other stakeholders
        $usersToNotify = collect([$task->assignedUser, $task->creator])
            ->filter()
            ->unique('id')
            ->reject(fn($u) => $u->id === $user->id);

        SendTaskNotificationJob::dispatch($task, 'comment_added', [
            'comment' => $comment,
            'users_to_notify' => $usersToNotify->pluck('id')->toArray()
        ]);

        return $comment;
    }
}

// app/Services/DashboardService.php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getUserDashboardStats(User $user): array
    {
        $projectsQuery = $user->is_admin 
            ? Project::query() 
            : $user->projects();

        $tasksQuery = $user->is_admin 
            ? Task::query() 
            : Task::where(function($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('created_by', $user->id)
                  ->orWhereHas('project.members', fn($q) => $q->where('user_id', $user->id));
            });

        return [
            'total_projects' => $projectsQuery->count(),
            'active_projects' => $projectsQuery->where('status', 'active')->count(),
            'total_tasks' => $tasksQuery->count(),
            'pending_tasks' => $tasksQuery->whereIn('status', ['todo', 'in-progress'])->count(),
            'completed_tasks' => $tasksQuery->where('status', 'done')->count(),
            'overdue_tasks' => $tasksQuery->overdue()->count(),
        ];
    }

    public function getRecentTasks(User $user, int $limit = 10): array
    {
        $tasks = Task::with(['project', 'assignedUser'])
            ->where(function($q) use ($user) {
                if (!$user->is_admin) {
                    $q->where('assigned_to', $user->id)
                      ->orWhere('created_by', $user->id);
                }
            })
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'project_name' => $task->project->name,
                    'assigned_to' => $task->assignedUser?->name,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'is_overdue' => $task->is_overdue,
                ];
            })
            ->toArray();

        return $tasks;
    }

    public function getUpcomingDeadlines(User $user, int $days = 7): array
    {
        $tasksQuery = Task::with(['project', 'assignedUser'])
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays($days))
            ->whereNotIn('status', ['done', 'cancelled']);

        if (!$user->is_admin) {
            $tasksQuery->where(function($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('created_by', $user->id);
            });
        }

        return $tasksQuery->orderBy('due_date')
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'project_name' => $task->project->name,
                    'assigned_to' => $task->assignedUser?->name,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'days_remaining' => $task->days_remaining,
                    'priority' => $task->priority,
                ];
            })
            ->toArray();
    }

    public function getProjectsChartData(User $user): array
    {
        $projectsQuery = $user->is_admin 
            ? Project::query() 
            : $user->projects();

        $statusCounts = $projectsQuery
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'labels' => array_keys($statusCounts),
            'data' => array_values($statusCounts),
            'colors' => [
                'planning' => '#fbbf24',
                'active' => '#10b981',
                'on-hold' => '#f59e0b',
                'completed' => '#3b82f6',
                'cancelled' => '#ef4444',
            ]
        ];
    }

    public function getTasksChartData(User $user): array
    {
        $tasksQuery = $user->is_admin 
            ? Task::query() 
            : Task::where(function($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhere('created_by', $user->id);
            });

        $statusCounts = $tasksQuery
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'labels' => array_keys($statusCounts),
            'data' => array_values($statusCounts),
            'colors' => [
                'todo' => '#6b7280',
                'in-progress' => '#f59e0b',
                'review' => '#8b5cf6',
                'done' => '#10b981',
            ]
        ];
    }

    public function getAdminDashboardStats(): array
    {
        return [
            'total_users' => User::count(),
            'active_users' => User::active()->count(),
            'total_projects' => Project::count(),
            'active_projects' => Project::active()->count(),
            'total_tasks' => Task::count(),
            'completed_tasks' => Task::where('status', 'done')->count(),
            'overdue_tasks' => Task::overdue()->count(),
            'users_joined_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    public function getUsersGrowthChart(): array
    {
        $data = User::selectRaw('DATE(created_at) as date, count(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'labels' => $data->pluck('date')->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d'))->toArray(),
            'data' => $data->pluck('count')->toArray(),
        ];
    }

    public function getProjectsStatusChart(): array
    {
        $statusCounts = Project::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'labels' => array_keys($statusCounts),
            'data' => array_values($statusCounts),
        ];
    }

    public function getTasksCompletionChart(): array
    {
        $data = Task::selectRaw('DATE(completed_at) as date, count(*) as count')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'labels' => $data->pluck('date')->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d'))->toArray(),
            'data' => $data->pluck('count')->toArray(),
        ];
    }

    public function getRecentActivity(int $limit = 10): array
    {
        return AuditLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user_name' => $log->user?->name ?? 'System',
                    'action' => $log->action,
                    'model_type' => class_basename($log->model_type),
                    'model_id' => $log->model_id,
                    'created_at' => $log->created_at->diffForHumans(),
                ];
            })
            ->toArray();
    }
}

// app/Jobs/SendProjectNotificationJob.php

namespace App\Jobs;

use App\Models\Project;
use App\Notifications\ProjectCreatedNotification;
use App\Notifications\ProjectUpdatedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class SendProjectNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public string $action,
        public array $oldData = []
    ) {}

    public function handle(): void
    {
        $members = $this->project->members;
        
        switch ($this->action) {
            case 'created':
                Notification::send($members, new ProjectCreatedNotification($this->project));
                break;
                
            case 'updated':
                Notification::send($members, new ProjectUpdatedNotification($this->project, $this->action, $this->oldData));
                break;
        }

        // Send webhook notifications if configured
        if ($webhookUrl = config('services.slack.webhook_url')) {
            $this->sendSlackNotification($webhookUrl);
        }
    }

    private function sendSlackNotification(string $webhookUrl): void
    {
        $message = match($this->action) {
            'created' => "🚀 New project created: *{$this->project->name}*",
            'updated' => "📝 Project updated: *{$this->project->name}*",
            default => "Project {$this->action}: *{$this->project->name}*"
        };

        // Simple webhook call - you could use a dedicated service for this
        $payload = json_encode([
            'text' => $message,
            'attachments' => [[
                'color' => 'good',
                'fields' => [
                    [
                        'title' => 'Manager',
                        'value' => $this->project->manager->name,
                        'short' => true
                    ],
                    [
                        'title' => 'Status',
                        'value' => ucfirst($this->project->status),
                        'short' => true
                    ]
                ]
            ]]
        ]);

        // Use HTTP client to send webhook
        try {
            \Illuminate\Support\Facades\Http::post($webhookUrl, json_decode($payload, true));
        } catch (\Exception $e) {
            // Log error but don't fail the job
            \Illuminate\Support\Facades\Log::error('Slack notification failed', [
                'error' => $e->getMessage(),
                'project_id' => $this->project->id
            ]);
        }
    }
}

// app/Jobs/SendTaskNotificationJob.php

namespace App\Jobs;

use App\Models\Task;
use App\Notifications\TaskStatusChangedNotification;
use App\Notifications\TaskCommentAddedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTaskNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Task $task,
        public string $action,
        public array $data = []
    ) {}

    public function handle(): void
    {
        switch ($this->action) {
            case 'status_changed':
                $this->handleStatusChanged();
                break;
                
            case 'comment_added':
                $this->handleCommentAdded();
                break;
                
            case 'created':
                $this->handleTaskCreated();
                break;
        }
    }

    private function handleStatusChanged(): void
    {
        $usersToNotify = collect([
            $this->task->assignedUser,
            $this->task->creator,
            $this->task->project->manager
        ])->filter()->unique('id');

        foreach ($usersToNotify as $user) {
            $user->notify(new TaskStatusChangedNotification($this->task, $this->data['old_status']));
        }
    }

    private function handleCommentAdded(): void
    {
        $userIds = $this->data['users_to_notify'] ?? [];
        $comment = $this->data['comment'];

        foreach ($userIds as $userId) {
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->notify(new TaskCommentAddedNotification($this->task, $comment));
            }
        }
    }

    private function handleTaskCreated(): void
    {
        // Notify project manager if different from creator
        if ($this->task->project->manager_id !== $this->task->created_by) {
            $this->task->project->manager->notify(
                new \App\Notifications\TaskCreatedNotification($this->task)
            );
        }
    }
}

// app/Jobs/SendDeadlineRemindersJob.php

namespace App\Jobs;

use App\Models\Task;
use App\Models\Project;
use App\Notifications\TaskDeadlineReminderNotification;
use App\Notifications\ProjectDeadlineReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDeadlineRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->sendTaskReminders();
        $this->sendProjectReminders();
    }

    private function sendTaskReminders(): void
    {
        // Tasks due in 24 hours
        $tasksDueSoon = Task::with(['assignedUser', 'project'])
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDay())
            ->whereNotIn('status', ['done'])
            ->whereNotNull('assigned_to')
            ->get();

        foreach ($tasksDueSoon as $task) {
            $task->assignedUser->notify(new TaskDeadlineReminderNotification($task));
        }

        // Overdue tasks
        $overdueTasks = Task::with(['assignedUser', 'creator', 'project'])
            ->overdue()
            ->whereNotNull('assigned_to')
            ->get();

        foreach ($overdueTasks as $task) {
            $task->assignedUser->notify(new TaskDeadlineReminderNotification($task, true));
            
            // Also notify creator if different
            if ($task->created_by !== $task->assigned_to) {
                $task->creator->notify(new TaskDeadlineReminderNotification($task, true));
            }
        }
    }

    private function sendProjectReminders(): void
    {
        // Projects due in 3 days
        $projectsDueSoon = Project::with('manager')
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays(3))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->get();

        foreach ($projectsDueSoon as $project) {
            $project->manager->notify(new ProjectDeadlineReminderNotification($project));
        }
    }
}

// app/Jobs/GenerateReportJob.php

namespace App\Jobs;

use App\Models\Project;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $reportType,
        public User $user,
        public array $parameters = []
    ) {}

    public function handle(ReportService $reportService): void
    {
        try {
            $filePath = match($this->reportType) {
                'project_summary' => $reportService->generateProjectSummaryReport($this->parameters),
                'team_performance' => $reportService->generateTeamPerformanceReport($this->parameters),
                'tasks_overview' => $reportService->generateTasksOverviewReport($this->parameters),
                default => throw new \InvalidArgumentException('Invalid report type')
            };

            // Notify user that report is ready
            $this->user->notify(new \App\Notifications\ReportGeneratedNotification($filePath, $this->reportType));

        } catch (\Exception $e) {
            // Notify user about error
            $this->user->notify(new \App\Notifications\ReportFailedNotification($this->reportType, $e->getMessage()));
            
            throw $e; // Re-throw to mark job as failed
        }
    }
}