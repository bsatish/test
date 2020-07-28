<?php
/**
 * 
 * COLAB - PHP Code Challenge
 * 
 * Below is a working, but ugly PHP function from a Drupal site we built. We'd
 * like you to take a look and refactor it so it is more understandable and
 * well structured. Please spend no more than 2-3 hours on it. When you're
 * done, send us your progress and we can walk through your code together.
 * 
 */

function _crf_webform_submission_access(User $user, $bundle, $webform_submission, $op) {
  // Whether the webform in question is a Funding Test
  $is_funding_test = in_array($webform_submission->webform_id->target_id, crf_webform_get_test_webform_ids());
  $source_entity = $webform_submission->getSourceEntity(); // This is the "Requesting Entity" node for RFFs, and the RFF node for funding tests.

  if ($is_funding_test) {
    $allowed_roles = [
      'administrator',
      'funding_coordinator',
      'funding_analyst',
      'funding_manager',
    ];

    $can_view_funding_tests = (count(array_intersect($allowed_roles, $user->getRoles())) > 0);
    if ($op === 'view' && $can_view_funding_tests) {
      return AccessResult::allowed()->cachePerUser();
    }

    $wrapper_entity = $source_entity;
    $moderation_state = $wrapper_entity->get('moderation_state')->value;
    if ($user->hasRole('funding_manager')) {
      $can_add_tests = crf_access_user_can_add_tests_to_rff($user, $wrapper_entity);
      $is_attached = false;
      foreach ($wrapper_entity->field_rff_test_submissions as $value) {
        if ($value->target_id === $webform_submission->id()) {
          $is_attached = true;
        }
      }
      switch ($op) {
        case 'create':
        case 'update':
          // Managers when assigned to RFF and RFF is in “Manager Review” state, and Test Webform Submission is referenced in Manager Tests field of RFF.
          if ($is_funding_test && $can_add_tests && $is_attached ) {
            return AccessResult::allowed()->cachePerUser();
          }
          else {
            return AccessResult::forbidden()->cachePerUser();
          }
          break;
          case 'duplicate':
          return AccessResult::forbidden()->cachePerUser();
        break;
      }
    }

    if ($user->hasRole('funding_analyst')) {
      $can_add_tests = crf_access_user_can_add_tests_to_rff($user, $wrapper_entity);
      $is_attached = false;
      foreach ($wrapper_entity->field_rff_test_submissions as $value) {
        if ($value->target_id === $webform_submission->id()) {
          $is_attached = true;
        }
      }
      switch ($op) {
        case 'create':
        case 'update':
          // Analyst when assigned to RFF, RFF is in “Analyst Review” state, and Test Webform Submission is referenced in Analyst Tests field of RFF.
          if ($is_funding_test && $is_attached && $can_add_tests) {

            return AccessResult::allowed()->cachePerUser();
          }
          else {
            return AccessResult::forbidden()->cachePerUser();
          }
          break;


          case 'duplicate':
          return AccessResult::forbidden()->cachePerUser();
        break;

      }
    }

    if ($user->hasRole('funding_coordinator')) {
      $is_assigned = $wrapper_entity->hasField('field_funding_coordinator_user') && $wrapper_entity->field_funding_coordinator_user->target_id === $user->id();

      switch ($op) {
          case 'update':
          case 'create':
          case 'duplicate':
            return AccessResult::forbidden()->cachePerUser();
          break;
      }
    }
  } // Non-funding tests - RFF
  else {
    $query = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'request_for_reimbursement',
          'field_requesting_entity' => $source_entity->id(),
          'field_request_details' => $webform_submission->id()
        ]);
    $wrapper_entity = reset($query); // This is the wrapper node for the webform request.
      // If there is no wrapper entity, this is likely not a webform submission we are interested in.
    if (!$wrapper_entity) {
      return AccessResult::neutral()->cachePerUser();
    }
    $moderation_state = $wrapper_entity->get('moderation_state')->value;

    // Reimbursement Request users.
    if ($user->hasRole('reimbursement_requester')) {
      // Account's requesting entity.
      $account_requesting_entity_id = $user->get('field_u_requesting_entity')->target_id;
      // Webform's requesting entity.
      $webform_requesting_entity_id = $source_entity->id();


      // Always deny access if the requesting entities do not match.
      if (empty($webform_requesting_entity_id) || $account_requesting_entity_id !== $webform_requesting_entity_id) {
        return AccessResult::forbidden()->cachePerUser();
      }

      switch ($op) {
        case 'view':
          // Role of reimbursement_requester can view self-owned submissions.
          return AccessResult::allowed()->cachePerUser();
        break;

        case 'update':
          // Can update when in “Needs Additional Documentation” state.
          if ($moderation_state === 'requires_additional_documentation') {
            return AccessResult::allowed()->cachePerUser();
          } else {
            return AccessResult::forbidden()->cachePerUser();
          }
          break;

        case 'create':
        case 'delete':
        case 'duplicate':
          return AccessResult::forbidden()->cachePerUser();
        break;
      }
    }
  }

  return AccessResult::neutral();
}
