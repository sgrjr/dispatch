<?php

namespace Sgrjr\Dispatch\Contracts;

/**
 * "Who is submitting" seam.
 *
 * Resolves the current authenticated user id for in-app captures, and a default
 * submitter id for system/CLI-created tasks (which have no logged-in user).
 * Returns integer user ids (both target apps key users by bigint).
 */
interface SubmitterResolver
{
    /**
     * The currently authenticated user's id, or null (guest / console).
     */
    public function currentUserId(): ?int;

    /**
     * The id to attribute system/CLI-created tasks to when there is no
     * authenticated user. May be null if the app allows unattributed tasks.
     */
    public function defaultUserId(): ?int;
}
