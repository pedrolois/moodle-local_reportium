@local @local_reportium @javascript
Feature: Explore an interactive report
  In order to understand the data of my site
  As a manager
  I need to see the rows of a report, search them and drill down into them

  Background:
    Given the following "local_reportium > reports" exist:
      | name        | summary              |
      | Fruit stock | Stock of every fruit |
    And the following "local_reportium > levels" exist:
      | report      | level | sql                                                                                                                                                                                                       |
      | Fruit stock | 1     | SELECT t.name, t.qty FROM (SELECT 'Apples' AS name, 3 AS qty UNION ALL SELECT 'Pears', 5 UNION ALL SELECT 'Plums', 2) t                                                                                   |
      | Fruit stock | 2     | SELECT t.variety, t.qty FROM (SELECT 'Apples' AS fruit, 'Fuji' AS variety, 1 AS qty UNION ALL SELECT 'Apples', 'Gala', 2 UNION ALL SELECT 'Pears', 'Conference', 5) t WHERE t.fruit = %%PREVIOUSVALUE%% |
    And I log in as "admin"

  Scenario: The first level shows every row
    When I am on the "Fruit stock" "local_reportium > View" page
    And I wait until the Reportium report is ready
    Then the Reportium table should have "3" rows
    And I should see "Apples" in the "[data-region='table']" "css_element"
    And I should see "Pears" in the "[data-region='table']" "css_element"
    And I should see "Plums" in the "[data-region='table']" "css_element"

  Scenario: The views of the toolbar show the same rows
    Given I am on the "Fruit stock" "local_reportium > View" page
    And I wait until the Reportium report is ready
    When I click on "[data-region='toolbar'] [data-action='view'][data-view='table']" "css_element"
    And I wait until the Reportium report is ready
    Then "[data-region='toolbar'] [data-view='table'].is-active" "css_element" should exist
    And the Reportium table should have "3" rows
    And I click on "[data-region='toolbar'] [data-action='view'][data-view='pie']" "css_element"
    And I wait until the Reportium report is ready
    And "[data-region='toolbar'] [data-view='pie'].is-active" "css_element" should exist
    And the Reportium table should have "3" rows

  Scenario: The search keeps only the matching rows
    Given I am on the "Fruit stock" "local_reportium > View" page
    And I wait until the Reportium report is ready
    When I set the field with xpath "//input[@data-region='search']" to "Pea"
    Then the Reportium table should have "1" row
    And I should see "Pears" in the "[data-region='table']" "css_element"
    And I should not see "Apples" in the "[data-region='table']" "css_element"

  Scenario: A row opens the next level and the breadcrumbs go back
    Given I am on the "Fruit stock" "local_reportium > View" page
    And I wait until the Reportium report is ready
    When I drill down into the "Apples" row of the Reportium report
    Then the Reportium table should have "2" rows
    And I should see "Fuji" in the "[data-region='table']" "css_element"
    And I should see "Gala" in the "[data-region='table']" "css_element"
    And I should not see "Conference" in the "[data-region='table']" "css_element"
    And I click on "[data-region='breadcrumbs'] [data-action='crumb']" "css_element"
    And I wait until the Reportium report is ready
    And the Reportium table should have "3" rows
