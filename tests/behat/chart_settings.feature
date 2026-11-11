@local @local_reportium @javascript
Feature: Chart settings of a report level
  In order to show a level the way I need
  As a manager
  I need to change its chart settings, its KPI cards and its calculated columns

  Background:
    Given the following "local_reportium > reports" exist:
      | name        | kpicards | kpirows |
      | Fruit stock | 1        | 1       |
    And the following "local_reportium > levels" exist:
      | report      | level | sql                                                                                                                                                                 |
      | Fruit stock | 1     | SELECT t.name, t.qty FROM (SELECT 'Apples' AS name, 3 AS qty UNION ALL SELECT 'Pears', 5 UNION ALL SELECT 'Plums', 2 UNION ALL SELECT 'Kiwis', 7) t |
    And I log in as "admin"
    And I am on the "Fruit stock" "local_reportium > View" page
    And I wait until the Reportium report is ready

  Scenario: Rows per page from the chart settings
    Given the Reportium table should have "4" rows
    When I open the Reportium "settings" panel
    And I set the field with xpath "//input[@data-setting='pagesize']" to "2"
    And I click on "[data-action='apply-settings']" "css_element"
    And I wait until the Reportium report is ready
    Then the Reportium table should have "2" rows
    And "[data-region='pager-0']" "css_element" should exist

  Scenario: The chart settings follow the chart on screen
    When I open the Reportium "settings" panel
    Then "[data-region='settings-panel'] [data-section='chart']" "css_element" should exist
    And "[data-region='settings-panel'] [data-setting='metric'][value='qty']" "css_element" should exist
    And I click on "[data-region='toolbar'] [data-action='view'][data-view='table']" "css_element"
    And I wait until the Reportium report is ready
    And "[data-region='settings-panel'] [data-section='chart']" "css_element" should not exist
    And "[data-region='settings-panel'] [data-section='table']" "css_element" should exist

  Scenario: A KPI card is added from its popover
    When I click on "[data-action='kpi-edit'][data-index='new']" "css_element"
    And "[data-region='kpi-popover']" "css_element" should be visible
    And I set the field with xpath "//select[@data-kpi-field='key']" to "qty"
    And I set the field with xpath "//select[@data-kpi-field='agg']" to "max"
    And I click on "[data-action='kpi-apply']" "css_element"
    Then "[data-region='kpi-popover']" "css_element" should not exist
    And "[data-region='kpi'][data-key='qty'][data-agg='max']" "css_element" should exist
    And I should see "7" in the "[data-region='kpi'][data-key='qty'][data-agg='max']" "css_element"

  Scenario: A calculated column is added from its popover
    Given I click on "[data-region='toolbar'] [data-action='view'][data-view='table']" "css_element"
    And I wait until the Reportium report is ready
    When I click on "[data-action='calc-edit'][data-key='new']" "css_element"
    And "[data-region='calc-popover']" "css_element" should be visible
    And I set the field with xpath "//input[@data-calc-field='label']" to "Double stock"
    And I set the field with xpath "//textarea[@data-calc-field='formula']" to "[qty] * 2"
    And I click on "[data-action='calc-apply']" "css_element"
    Then "[data-region='calc-popover']" "css_element" should not exist
    And I should see "Double stock" in the "[data-region='table'] thead" "css_element"
    And I should see "14" in the "[data-region='table'] tbody" "css_element"
