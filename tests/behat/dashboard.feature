@local @local_aicharts @javascript
Feature: Chart dashboard with the offline sample answers
  In order to build charts from plain-language requests
  As an admin
  I need to generate, save, edit and review charts on the dashboard

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
    And "Add chart" "button" should exist

  Scenario: Generate a chart with the sample answers and save it
    When I click on "Add chart" "button"
    And I set the field "Describe your chart" in the "Add chart" "dialogue" to "users per course"
    And I click on "Generate" "button" in the "Add chart" "dialogue"
    Then I should see "Preview" in the "Add chart" "dialogue"
    And the field "Chart name" in the "Add chart" "dialogue" matches value "Users per course"
    And I should see "SQL that will run on every load" in the "Add chart" "dialogue"
    And I should see "Model notes: Counts enrolled users, whatever the enrolment status." in the "Add chart" "dialogue"
    When I click on "Save changes" "button" in the "Add chart" "dialogue"
    Then I should see "Chart saved."
    And I should see "Users per course"
    And "Add chart" "dialogue" should not exist

  Scenario: The filter narrows the cards by name
    Given I click on "Add chart" "button"
    And I set the field "Describe your chart" in the "Add chart" "dialogue" to "users per course"
    And I click on "Generate" "button" in the "Add chart" "dialogue"
    And I should see "Preview" in the "Add chart" "dialogue"
    And I click on "Save changes" "button" in the "Add chart" "dialogue"
    And I should see "Chart saved."
    When I set the field "Filter charts" to "users per"
    Then I should see "Showing 3 of 7 items."
    And I should see "Users per course"
    And I should see "Users per role"
    And I should see "New users per week"
    And I should not see "Courses per category"
    When I set the field "Filter charts" to "zzzz"
    Then I should see "No items match."
    And I should not see "Users per role"

  Scenario: Closing the modal after a preview asks before discarding it
    When I click on "Add chart" "button"
    And I set the field "Describe your chart" in the "Add chart" "dialogue" to "users per course"
    And I click on "Generate" "button" in the "Add chart" "dialogue"
    And I should see "Preview" in the "Add chart" "dialogue"
    And I press the escape key
    Then I should see "Discard the generated preview?"
    When I click on "Cancel" "button" in the "Discard preview" "dialogue"
    Then "Add chart" "dialogue" should exist
    And I should see "Preview" in the "Add chart" "dialogue"
    When I press the escape key
    And I click on "Discard" "button" in the "Discard preview" "dialogue"
    Then "Add chart" "dialogue" should not exist

  Scenario: Edit a saved chart from the list view
    Given I click on "List" "link"
    When I open the action menu in "Enrolments per course" "table_row"
    And I choose "Edit" in the open action menu
    Then I should see "Preview" in the "Edit chart" "dialogue"
    And the field "Chart name" in the "Edit chart" "dialogue" matches value "Enrolments per course"
    When I set the field "Chart name" in the "Edit chart" "dialogue" to "Enrolments per course, renamed"
    And I click on "Save changes" "button" in the "Edit chart" "dialogue"
    Then I should see "Chart saved."
    And I should see "Enrolments per course, renamed"

  Scenario: A scheduled chart runs once on save and shows its run history
    When I click on "Add chart" "button"
    And I set the field "Describe your chart" in the "Add chart" "dialogue" to "users per course"
    And I click on "Generate" "button" in the "Add chart" "dialogue"
    And I should see "Preview" in the "Add chart" "dialogue"
    And I click on "Daily" "radio" in the "Add chart" "dialogue"
    Then "Run time" "select" should be visible
    When I click on "Save changes" "button" in the "Add chart" "dialogue"
    Then I should see "Chart saved."
    And I should see "Users per course"
    When I follow "Run history"
    Then I should see "Users per course"
    And I should see "Scheduled daily at"
    And I should see "30 runs kept"
    And I should see "First run after save"
    And I should see "OK"
    And I should see "Back to charts"

  Scenario: A request for a list produces a query-only table
    When I click on "Add chart" "button"
    And I set the field "Describe your chart" in the "Add chart" "dialogue" to "list the users who never logged in"
    And I click on "Generate" "button" in the "Add chart" "dialogue"
    Then the field "Chart name" in the "Add chart" "dialogue" matches value "Users who never logged in"
    And I should see "Model notes: Suspended users are included." in the "Add chart" "dialogue"
    And "table" "css_element" should exist in the "Add chart" "dialogue"
    And I should see "lastname" in the "Add chart" "dialogue"
    And I should see "student1@example.com" in the "Add chart" "dialogue"
    When I click on "Save changes" "button" in the "Add chart" "dialogue"
    Then I should see "Chart saved."
    And I should see "Users who never logged in"
