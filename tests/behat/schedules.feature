@local @local_reportium @javascript
Feature: Email a report on a schedule
  In order to receive a report without opening it
  As a manager
  I need to schedule emails of a level of the report

  Background:
    Given the following "local_reportium > reports" exist:
      | name        |
      | Fruit stock |
    And the following "local_reportium > levels" exist:
      | report      | level | sql                                                                                                                     |
      | Fruit stock | 1     | SELECT t.name, t.qty FROM (SELECT 'Apples' AS name, 3 AS qty UNION ALL SELECT 'Pears', 5 UNION ALL SELECT 'Plums', 2) t |
    And I log in as "admin"

  Scenario: A schedule with an email address is saved and listed
    Given I am on the "Fruit stock" "local_reportium > Scheduled emails" page
    And I should see "This report is not emailed yet."
    When I click on "Add schedule" "link"
    And I set the following fields to these values:
      | Other email addresses | team@example.com |
      | Subject               | Weekly fruit     |
    And I press "Save schedule"
    Then I should see "Schedule saved."
    And I should see "1 other address(es)"
    And I should not see "This report is not emailed yet."

  Scenario: A schedule needs a recipient
    Given I am on the "Fruit stock" "local_reportium > Scheduled emails" page
    When I click on "Add schedule" "link"
    And I press "Save schedule"
    Then I should see "Choose at least one recipient."
