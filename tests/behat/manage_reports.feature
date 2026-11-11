@local @local_reportium
Feature: Create reports and decide who sees them
  In order to share data with the right people
  As a manager
  I need to create reports and have them shown only to the roles they are shared with

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "local_reportium > reports" exist:
      | name          | roles |
      | Manager stock |       |
      | Shared stock  | user  |
    And the following "local_reportium > levels" exist:
      | report        | level | sql                                            |
      | Manager stock | 1     | SELECT t.name FROM (SELECT 'Apples' AS name) t |
      | Shared stock  | 1     | SELECT t.name FROM (SELECT 'Pears' AS name) t  |

  Scenario: A new report is listed with the reports of the site
    Given I log in as "admin"
    And I am on the "local_reportium > Reports" page
    And I should see "Manager stock"
    When I click on "New report" "link"
    And I set the field "Name" to "Course activity"
    And I press "Add"
    And I am on the "local_reportium > Reports" page
    Then I should see "Course activity"
    And I should see "Manager stock"

  @javascript
  Scenario: A report shared with every logged in user is shown to a student
    Given I log in as "student1"
    When I am on the "Shared stock" "local_reportium > View" page
    And I wait until the Reportium report is ready
    Then I should see "Pears" in the "[data-region='table']" "css_element"
    And "[data-region='sidetabs'] [data-panel='sql']" "css_element" should not exist
