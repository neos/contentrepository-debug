@contentrepository @adapters=DoctrineDBAL
Feature: PruneRemovedContentStreamsTool — delete event history of removed content streams

  Background:
    Given using no content dimensions
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Document': {}
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "test-user"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value     |
      | workspaceName      | "live"    |
      | newContentStreamId | "cs-live" |
    And I am in workspace "live"
    And I am in dimension space point {}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "root"                        |
      | nodeTypeName    | "Neos.ContentRepository:Root" |

  # ---------------------------------------------------------------------------
  # Happy path: deleted workspace leaves a pruneable content stream
  # ---------------------------------------------------------------------------

  Scenario: After deleting a workspace its content stream history can be pruned
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value      |
      | workspaceName      | "user"     |
      | baseWorkspaceName  | "live"     |
      | newContentStreamId | "cs-user"  |
    And the command DeleteWorkspace is executed with payload:
      | Key           | Value  |
      | workspaceName | "user" |
    And the explore context is:
      | cr | default |
    When I execute the explore tool "PruneRemovedContentStreamsTool" with inputs:
      | answer | yes |
    Then the tool output should contain "cs-user"
    And the tool output should contain "Done"

  # ---------------------------------------------------------------------------
  # Nothing to prune on a fresh CR
  # ---------------------------------------------------------------------------

  Scenario: Fresh CR with no removed content streams reports nothing to prune
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "PruneRemovedContentStreamsTool" with inputs:
      | answer | yes |
    Then the tool output should contain "No pruneable content streams"

  # ---------------------------------------------------------------------------
  # Abort when user declines confirmation
  # ---------------------------------------------------------------------------

  Scenario: Declining confirmation aborts without deleting anything
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value      |
      | workspaceName      | "user"     |
      | baseWorkspaceName  | "live"     |
      | newContentStreamId | "cs-user"  |
    And the command DeleteWorkspace is executed with payload:
      | Key           | Value  |
      | workspaceName | "user" |
    And the explore context is:
      | cr | default |
    When I execute the explore tool "PruneRemovedContentStreamsTool" with inputs:
      | answer | no |
    Then the tool output should contain "Aborted"
    And the tool output should not contain "Done"
