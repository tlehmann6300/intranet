<?php
/**
 * Poll Helper Functions
 * Shared functions for poll filtering and display logic
 */

/**
 * Check whether a poll is visible to a given user.
 *
 * Visibility rules (any one is sufficient):
 *   a) target_groups is 'all', empty, or the visible_to_all flag is set.
 *   b) The user's Entra role(s) are listed in the poll's target_roles.
 *   c) The user is the creator of the poll.
 *
 * @param array      $poll          Poll record from database
 * @param array      $userAzureRoles User's Entra/Azure roles
 * @param int|null   $userId        Current user's ID
 * @return bool
 */
function isPollVisibleForUser($poll, $userAzureRoles = [], $userId = null) {
    // a) Visible to all: visible_to_all flag set, or target_groups is 'all' or empty/null
    $targetGroups = $poll['target_groups'] ?? '';
    if (!empty($poll['visible_to_all']) || empty($targetGroups) || $targetGroups === 'all') {
        return true;
    }

    // b) User's Entra role is in target_roles
    $targetRoles = !empty($poll['target_roles']) ? json_decode($poll['target_roles'], true) : null;
    if ($targetRoles !== null && is_array($targetRoles) && count($targetRoles) > 0) {
        foreach ((array)$userAzureRoles as $role) {
            if (in_array($role, $targetRoles)) {
                return true;
            }
        }
    }

    // c) User is the creator
    if ($userId !== null && !empty($poll['created_by']) && (int)$poll['created_by'] === (int)$userId) {
        return true;
    }

    return false;
}

/**
 * Filter polls based on user's roles and poll settings
 * 
 * @param array      $polls         Array of poll records from database
 * @param string     $userRole      User's system role (legacy)
 * @param array      $userAzureRoles User's Entra/Azure roles
 * @param int|null   $userId        Current user's ID (for creator check)
 * @return array Filtered array of polls visible to the user
 */
function filterPollsForUser($polls, $userRole, $userAzureRoles = [], $userId = null) {
    return array_filter($polls, function($poll) use ($userAzureRoles, $userId) {
        // Skip if user has manually hidden this poll
        if (!empty($poll['user_has_hidden']) && $poll['user_has_hidden'] > 0) {
            return false;
        }

        if (!isPollVisibleForUser($poll, $userAzureRoles, $userId)) {
            return false;
        }

        // For internal polls, hide if user has already voted
        if (!empty($poll['is_internal']) && !empty($poll['user_has_voted']) && $poll['user_has_voted'] > 0) {
            return false;
        }

        return true;
    });
}
