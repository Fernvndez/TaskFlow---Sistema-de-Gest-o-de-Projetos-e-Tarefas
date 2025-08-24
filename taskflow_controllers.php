<?php
// app/Http/Controllers/Api/ProjectController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $projects = Project::with(['manager', 'members', 'tasks'])
            ->when($request->status, fn($q) => $q->byStatus($request->status))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => ProjectResource::collection($projects),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'total' => $projects->total(),
                'per_page' => $projects->perPage(),
            ]
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projectService->createProject($request->validated());

        return response()->json([
            'message' => 'Project created successfully',
            'data' => new ProjectResource($project->load(['manager', 'members']))
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load(['manager', 'members', 'tasks.assignedUser', 'tasks.comments']);

        return response()->json([
            'data' => new ProjectResource($project)
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project = $this->projectService->updateProject($project, $request->validated());

        return response()->json([
            'message' => 'Project updated successfully',
            'data' => new ProjectResource($project->load(['manager', 'members']))
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->projectService->deleteProject($project);

        return response()->json([
            'message' => 'Project deleted successfully'
        ]);
    }

    public function addMember(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:member,lead,viewer'
        ]);

        $this->projectService->addMemberToProject($project, $request->user_id, $request->role);

        return response()->json([
            'message' => 'Member added to project successfully'
        ]);
    }

    public function removeMember(Project $project, int $userId): JsonResponse
    {
        $this->projectService->removeMemberFromProject($project, $userId);

        return response()->json([
            'message' => 'Member removed from project successfully'
        ]);
    }

    public function metrics(Project $project): JsonResponse
    {
        $metrics = $this->projectService->getProjectMetrics($project);

        return response()->json([
            'data' => $metrics
        ]);
    }
}

// app/Http/Controllers/Api/TaskController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tasks = Task::with(['project', 'assignedUser', 'creator'])
            ->when($request->project_id, fn($q) => $q->where('project_id', $request->project_id))
            ->when($request->assigned_to, fn($q) => $q->assignedTo($request->assigned_to))
            ->when($request->status, fn($q) => $q->byStatus($request->status))
            ->when($request->priority, fn($q) => $q->where('priority', $request->priority))
            ->when($request->overdue, fn($q) => $q->overdue())
            ->orderBy('priority', 'desc')
            ->orderBy('due_date', 'asc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => TaskResource::collection($tasks),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'total' => $tasks->total(),
                'per_page' => $tasks->perPage(),
            ]
        ]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $task = $this->taskService->createTask($request->validated());

        return response()->json([
            'message' => 'Task created successfully',
            'data' => new TaskResource($task->load(['project', 'assignedUser', 'creator']))
        ], 201);
    }

    public function show(Task $task): JsonResponse
    {
        $task->load(['project', 'assignedUser', 'creator', 'comments.user']);

        return response()->json([
            'data' => new TaskResource($task)
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $task = $this->taskService->updateTask($task, $request->validated());

        return response()->json([
            'message' => 'Task updated successfully',
            'data' => new TaskResource($task->load(['project', 'assignedUser', 'creator']))
        ]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $this->taskService->deleteTask($task);

        return response()->json([
            'message' => 'Task deleted successfully'
        ]);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:todo,in-progress,review,done'
        ]);

        $task = $this->taskService->updateTaskStatus($task, $request->status);

        return response()->json([
            'message' => 'Task status updated successfully',
            'data' => new TaskResource($task)
        ]);
    }

    public function addComment(Request $request, Task $task): JsonResponse
    {
        $request->validate([
            'content' => 'required|string|min:1',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string'
        ]);

        $comment = $this->taskService->addCommentToTask(
            $task,
            auth()->user(),
            $request->content,
            $request->attachments ?? []
        );

        return response()->json([
            'message' => 'Comment added successfully',
            'data' => $comment->load('user')
        ], 201);
    }
}

// app/Http/Controllers/Api/AuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'developer',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive. Please contact an administrator.'
            ], 403);
        }

        $user->updateLastActivity();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->load(['managedProjects', 'projects'])
        ]);
    }
}

// app/Http/Controllers/Web/DashboardController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        
        $stats = $this->dashboardService->getUserDashboardStats($user);
        $recentTasks = $this->dashboardService->getRecentTasks($user, 5);
        $upcomingDeadlines = $this->dashboardService->getUpcomingDeadlines($user, 5);
        
        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentTasks' => $recentTasks,
            'upcomingDeadlines' => $upcomingDeadlines,
            'projectsChart' => $this->dashboardService->getProjectsChartData($user),
            'tasksChart' => $this->dashboardService->getTasksChartData($user),
        ]);
    }

    public function adminDashboard()
    {
        if (!auth()->user()->is_admin) {
            abort(403);
        }

        $stats = $this->dashboardService->getAdminDashboardStats();
        
        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'usersChart' => $this->dashboardService->getUsersGrowthChart(),
            'projectsChart' => $this->dashboardService->getProjectsStatusChart(),
            'tasksChart' => $this->dashboardService->getTasksCompletionChart(),
            'activityLog' => $this->dashboardService->getRecentActivity(10),
        ]);
    }
}

// app/Http/Controllers/Web/ProjectController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $projectService
    ) {}

    public function index(Request $request)
    {
        $projects = Project::with(['manager', 'members'])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->status, fn($q) => $q->byStatus($request->status))
            ->paginate(12);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => $request->only(['search', 'status']),
            'statusOptions' => ['planning', 'active', 'on-hold', 'completed', 'cancelled'],
        ]);
    }

    public function create()
    {
        $managers = User::byRole('manager')->orWhere('role', 'admin')->get();

        return Inertia::render('Projects/Create', [
            'managers' => $managers,
        ]);
    }

    public function store(StoreProjectRequest $request)
    {
        $project = $this->projectService->createProject($request->validated());

        return redirect()->route('projects.show', $project)
                        ->with('success', 'Project created successfully!');
    }

    public function show(Project $project)
    {
        $project->load([
            'manager',
            'members',
            'tasks.assignedUser',
            'tasks.comments.user'
        ]);

        $metrics = $this->projectService->getProjectMetrics($project);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'metrics' => $metrics,
            'canEdit' => auth()->user()->can('update', $project),
        ]);
    }

    public function edit(Project $project)
    {
        $this->authorize('update', $project);

        $managers = User::byRole('manager')->orWhere('role', 'admin')->get();

        return Inertia::render('Projects/Edit', [
            'project' => $project,
            'managers' => $managers,
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $this->authorize('update', $project);

        $project = $this->projectService->updateProject($project, $request->validated());

        return redirect()->route('projects.show', $project)
                        ->with('success', 'Project updated successfully!');
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $this->projectService->deleteProject($project);

        return redirect()->route('projects.index')
                        ->with('success', 'Project deleted successfully!');
    }
}