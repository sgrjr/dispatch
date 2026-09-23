<?php

namespace Sgrjr\Dispatch\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Something that is BLOCKED until a person says yes or no (PU-2.10, TASK-1021,
 * rulings R18/R19).
 *
 * "An LLM requesting a session token to do work should dispatch a special kind
 * of task that times out… 'approval requested' as a global mechanism for
 * granting not just LLMs but all sorts of blocking things requiring approval."
 * An approvable files ONE "Approval requested" task (ApprovalTasks::file), and
 * that task is the thing a human acts on: Approve or Deny. Agent-session
 * requests are the first implementation; timesheets, inventory acquisitions
 * and the like adopt the same contract later (PU-5.7, TASK-1022).
 *
 * Kinds are registered in config('dispatch.approvals.kinds'), [kind => class],
 * so an approval task can find its approvable again from its marker.
 */
interface Approvable
{
    /** The registered kind, e.g. 'agent_session'. Also the task's topic/origin type. */
    public static function approvalKind(): string;

    /** Look an approvable up by the id its task carries. */
    public static function findForApproval(string $id): ?self;

    /** Stable id within the kind (e.g. an agent session's public_id). */
    public function approvalId(): string;

    /** One line for the task title: "Approval requested: <label>". */
    public function approvalLabel(): string;

    /** Markdown body for the task: what is being asked, and what approving grants. */
    public function approvalDetails(): string;

    /** When the request lapses unanswered. The task's due_at; past it the task closes as expired. */
    public function approvalExpiresAt(): ?CarbonInterface;

    /** The lane of the people who may decide (null = the no-department lane). */
    public function approvalLane(): ?string;

    /** Still waiting on a decision? */
    public function approvalIsPending(): bool;

    /**
     * Grant it. `$options` carries kind-specific choices the approver made
     * (for an agent session: ttl, scopes, lane). The implementation MUST close
     * the approval task through ApprovalTasks::resolve(): one closer, whether
     * the decision came from the task or from the kind's own screen.
     *
     * @param  array<string, mixed>  $options
     */
    public function approveBy(Authenticatable $user, array $options = []): void;

    /** Refuse it. Same one-closer rule as approveBy(). */
    public function denyBy(Authenticatable $user): void;
}
