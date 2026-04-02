@contentrepository @adapters=DoctrineDBAL
Feature: CompactEventsTool — fold consecutive NodePropertiesWereSet streaks

  Background:
    Given using the following content dimensions:
      | Identifier | Values | Generalizations |
      | language   | mul    |                 |
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Document':
      properties:
        title:
          type: string
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "test-user"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |
    And I am in workspace "live"
    And I am in dimension space point {"language":"mul"}
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "root"                        |
      | nodeTypeName    | "Neos.ContentRepository:Root" |
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | nodeTypeName                            | parentNodeAggregateId | nodeName | originDimensionSpacePoint |
      | page-1          | Neos.ContentRepository.Testing:Document | root                  | page-1   | {"language":"mul"}        |

  # ---------------------------------------------------------------------------
  # Core compaction behaviour
  # ---------------------------------------------------------------------------

  Scenario: Three consecutive NodePropertiesWereSet for same node are compacted to one
    Given the command SetNodeProperties is executed with payload:
      | Key             | Value                  |
      | nodeAggregateId | "page-1"               |
      | propertyValues  | {"title": "First"}     |
    And the command SetNodeProperties is executed with payload:
      | Key             | Value                  |
      | nodeAggregateId | "page-1"               |
      | propertyValues  | {"title": "Second"}    |
    And the command SetNodeProperties is executed with payload:
      | Key             | Value                  |
      | nodeAggregateId | "page-1"               |
      | propertyValues  | {"title": "Third"}     |
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CompactEventsTool" with inputs:
      | answer | yes |
      | answer | yes |
    Then the tool output should contain "Compacted"
    And the tool output should contain "deleted 2"

  # ---------------------------------------------------------------------------
  # Streak broken by another event type
  # ---------------------------------------------------------------------------

  Scenario: Streak broken by TagSubtree — only within-streak pairs are compacted
    Given the command SetNodeProperties is executed with payload:
      | Key             | Value               |
      | nodeAggregateId | "page-1"            |
      | propertyValues  | {"title": "First"}  |
    And the command SetNodeProperties is executed with payload:
      | Key             | Value               |
      | nodeAggregateId | "page-1"            |
      | propertyValues  | {"title": "Second"} |
    And the command TagSubtree is executed with payload:
      | Key                          | Value          |
      | nodeAggregateId              | "page-1"       |
      | nodeVariantSelectionStrategy | "allVariants"  |
      | tag                          | "some-tag"     |
    And the command SetNodeProperties is executed with payload:
      | Key             | Value               |
      | nodeAggregateId | "page-1"            |
      | propertyValues  | {"title": "Third"}  |
    And the command SetNodeProperties is executed with payload:
      | Key             | Value               |
      | nodeAggregateId | "page-1"            |
      | propertyValues  | {"title": "Fourth"} |
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CompactEventsTool" with inputs:
      | answer | yes |
      | answer | yes |
    Then the tool output should contain "Compacted"
    And the tool output should contain "deleted 2"

  # ---------------------------------------------------------------------------
  # Nothing to compact
  # ---------------------------------------------------------------------------

  Scenario: Single NodePropertiesWereSet — nothing to compact
    Given the command SetNodeProperties is executed with payload:
      | Key             | Value              |
      | nodeAggregateId | "page-1"           |
      | propertyValues  | {"title": "Hello"} |
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CompactEventsTool" with inputs:
      | answer | yes |
      | answer | yes |
    Then the tool output should contain "Nothing to compact"

  # ---------------------------------------------------------------------------
  # Abort on wrong confirmation
  # ---------------------------------------------------------------------------

  Scenario: Answering anything other than "yes" aborts without changes
    Given the command SetNodeProperties is executed with payload:
      | Key             | Value              |
      | nodeAggregateId | "page-1"           |
      | propertyValues  | {"title": "Hello"} |
    And the command SetNodeProperties is executed with payload:
      | Key             | Value              |
      | nodeAggregateId | "page-1"           |
      | propertyValues  | {"title": "World"} |
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CompactEventsTool" with inputs:
      | answer | yes |
      | answer | no  |
    Then the tool output should contain "Aborted"
    And the tool output should not contain "Compacted"
