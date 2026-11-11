@local @local_reportium @javascript
Feature: Change the SQL of a report from the SQL Inspector
  In order to adapt a report
  As a manager
  I need to edit, validate and save the SQL of its levels and see its versions

  Background:
    Given the following "local_reportium > reports" exist:
      | name        |
      | Fruit stock |
    And the following "local_reportium > levels" exist:
      | report      | level | sql                                                                                                                     |
      | Fruit stock | 1     | SELECT t.name, t.qty FROM (SELECT 'Apples' AS name, 3 AS qty UNION ALL SELECT 'Pears', 5 UNION ALL SELECT 'Plums', 2) t |
    And I log in as "admin"
    And I am on the "Fruit stock" "local_reportium > View" page
    And I wait until the Reportium report is ready
    And I open the Reportium "sql" panel

  Scenario: The inspector shows the SQL of the level on screen
    Then "[data-region='sql-drawer'] .reportium-sql-pill.is-active[data-level='0']" "css_element" should exist
    And the field with xpath "//textarea[@data-region='sql-text']" matches value "SELECT t.name, t.qty FROM (SELECT 'Apples' AS name, 3 AS qty UNION ALL SELECT 'Pears', 5 UNION ALL SELECT 'Plums', 2) t"

  Scenario: Saved SQL changes the rows and keeps a version
    When I set the field with xpath "//textarea[@data-region='sql-text']" to "SELECT t.name, t.qty FROM (SELECT 'Kiwis' AS name, 7 AS qty) t"
    And I click on "[data-action='save-sql']" "css_element"
    Then I should see "SQL saved." in the "[data-region='sql-status']" "css_element"
    And I should see "Kiwis" in the "[data-region='table']" "css_element"
    And I should not see "Apples" in the "[data-region='table']" "css_element"
    And I click on "[data-action='sql-history']" "css_element"
    And "[data-region='sql-history'] [data-region='history-item']" "css_element" should exist

  Scenario: SQL that changes data is refused
    When I set the field with xpath "//textarea[@data-region='sql-text']" to "DELETE FROM {user}"
    And I click on "[data-action='save-sql']" "css_element"
    Then "[data-region='sql-status'].text-danger" "css_element" should exist
    And I should see "Apples" in the "[data-region='table']" "css_element"
