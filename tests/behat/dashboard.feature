@local @local_aicharts @javascript
Feature: Chart dashboard
  In order to follow the charts of my site
  As an admin
  I need to list, filter, run and pause the saved charts

  Background:
    Given the following config values are set as admin:
      | clienttype | stub | local_aicharts |
    And the following "courses" exist:
      | fullname   | shortname |
      | Course one | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Sue       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And I log in as "admin"
    And I navigate to "Reports > AI charts" in site administration

  Scenario: The dashboard lists the shipped charts
    Then I should see "Enrolments per course"
    And I should see "Users who have never logged in"
    And "Add chart" "link" should exist

  Scenario: The filter narrows the cards by name
    When I set the field "Filter charts" to "users per"
    Then I should see "Showing 2 of 7 items."
    And I should see "Users per role"
    And I should see "New users per week"
    And I should not see "Courses per category"
    When I set the field "Filter charts" to "zzzz"
    Then I should see "No items match."
    And I should not see "Users per role"

  Scenario: A live chart opens its page from its name with the current result and a CSV
    When I follow "Enrolments per course"
    Then I should see "Enrolments per course"
    And I should see "Live — runs when the dashboard or this page loads"
    And I should see "ran just now"
    And "Download CSV" "link" should exist
    And I should not see "Runs"
    And I should see "Back to charts"

  Scenario: A deferred live card with no stored run offers Run now alone
    Given I visit "/local/aicharts/index.php?skiplive=1"
    Then I should see "Not run yet — this live query runs when you ask."
    And I should see "limit 500 · not run"
    And "Show chart" "button" should not exist
    And "Run now" "button" should exist in the "[data-region=aic-card][data-name='enrolments per course']" "css_element"

  Scenario: Run now on a live card that has never run stores the run at once
    Given I visit "/local/aicharts/index.php?skiplive=1"
    When I click on "Run now" "button" in the "[data-region=aic-card][data-name='enrolments per course']" "css_element"
    Then I should see "Run stored"
    And I should see "last run:" in the "[data-region=aic-card][data-name='enrolments per course']" "css_element"
    When I visit "/local/aicharts/index.php?skiplive=1"
    Then "Run again" "button" should exist in the "[data-region=aic-card][data-name='enrolments per course']" "css_element"
    And I should not see "Not run yet" in the "[data-region=aic-card][data-name='enrolments per course']" "css_element"

  Scenario: Pause a live chart from its card
    When I open the action menu in "[data-region=aic-card][data-name='enrolments per course']" "css_element"
    And I choose "Pause" in the open action menu
    Then I should see "Chart paused."
    And I should see "Paused — not run until resumed."
