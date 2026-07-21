<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\ProjectMembership;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project team management. Project-scoped (ProjectContext set by ResolveProject). The last admin of
 * a project cannot be removed.
 */
final class ProjectMembershipController extends Controller
{
    private const ADMIN_ROLES = ['account_manager', 'client_admin'];

    private const ROLES = [
        'account_manager', 'media_buyer', 'analyst', 'content', 'finance',
        'client_admin', 'client_approver', 'client_viewer', 'viewer',
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.view'), 403);

        $members = ProjectMembership::with('user:id,name,email')->get()->map(fn (ProjectMembership $m) => [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => $m->user?->name,
            'email' => $m->user?->email,
            'role' => $m->role,
            'status' => $m->status,
            'expires_at' => optional($m->expires_at)->toIso8601String(),
        ]);

        return ApiResponse::success($members, 'Project team retrieved.');
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.invite'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'role' => ['required', Rule::in(self::ROLES)],
            'expires_at' => ['nullable', 'date'],
        ]);

        $member = ProjectMembership::updateOrCreate(
            ['user_id' => $validated['user_id']],
            ['role' => $validated['role'], 'status' => 'active', 'joined_at' => now(), 'expires_at' => $validated['expires_at'] ?? null, 'created_by' => $request->user()->id],
        );

        $audit->log(action: 'project.member.added', entityType: ProjectMembership::class, entityId: (string) $member->id, after: ['user' => $member->user_id, 'role' => $member->role]);

        return ApiResponse::success(['id' => $member->id, 'role' => $member->role], 'Member added.', status: 201);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.update'), 403);
        $membershipId = (string) $request->route('membership');

        $member = ProjectMembership::find($membershipId);
        abort_if($member === null, 404, 'Member not found in this project.');

        $validated = $request->validate([
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'status' => ['sometimes', Rule::in(['invited', 'active', 'suspended', 'expired', 'removed'])],
            'permissions' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // Do not allow demoting/suspending the last admin.
        $wasAdmin = in_array($member->role, self::ADMIN_ROLES, true);
        $becomingNonAdmin = isset($validated['role']) && ! in_array($validated['role'], self::ADMIN_ROLES, true);
        $suspending = ($validated['status'] ?? null) === 'suspended';
        if ($wasAdmin && ($becomingNonAdmin || $suspending)) {
            $otherAdmins = ProjectMembership::whereIn('role', self::ADMIN_ROLES)
                ->where('status', 'active')
                ->where('id', '!=', $member->id)
                ->count();
            abort_if($otherAdmins === 0, 422, 'Cannot demote or suspend the last admin of the project.');
        }

        $before = $member->only(['role', 'status']);
        $member->update($validated);
        $audit->log(action: 'project.member.updated', entityType: ProjectMembership::class, entityId: (string) $member->id, before: $before, after: $member->only(['role', 'status']));

        return ApiResponse::success(['id' => $member->id, 'role' => $member->role, 'status' => $member->status], 'Member updated.');
    }

    public function destroy(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.remove'), 403);
        $membershipId = (string) $request->route('membership');

        $member = ProjectMembership::find($membershipId);
        abort_if($member === null, 404, 'Member not found in this project.');

        // Prevent removing the last admin.
        if (in_array($member->role, self::ADMIN_ROLES, true)) {
            $otherAdmins = ProjectMembership::whereIn('role', self::ADMIN_ROLES)
                ->where('id', '!=', $member->id)
                ->count();
            abort_if($otherAdmins === 0, 422, 'Cannot remove the last admin of the project.');
        }

        $audit->log(action: 'project.member.removed', entityType: ProjectMembership::class, entityId: (string) $member->id, before: ['user' => $member->user_id, 'role' => $member->role]);
        $member->delete();

        return ApiResponse::success(null, 'Member removed.');
    }
}
