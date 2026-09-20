@local @local_aicharts @javascript
Feature: Chart wizard
  In order to build a chart step by step
  As an admin
  I need to choose a kind, add a series by hand and reach the next step

  Background:
    Given the following "courses" exist:
      | fullname   | shortname |
      | Course one | C1        |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And I log in as "admin"

  Scenario: Choose the kind, add a series and go on to the chart step
    Given I visit "/local/aicharts/edit.php"
    Then I should see "Step 1 of 5"
    When I click on "One-shot" "radio"
    And I press "Next"
    Then I should see "Step 2 of 5"
    And I should see "Kind: One-shot"
    When I click on "Add series" "button"
    And I set the following fields in the "Add series" "dialogue" to these values:
      | Series name | Courses                                                                 |
      | SQL         | SELECT c.shortname AS label, c.id AS total FROM {course} c WHERE c.id > :siteid |
      | Parameters  | {"siteid": 1}                                                           |
    And I click on "Run" "button" in the "Add series" "dialogue"
    Then I should see "1 rows returned" in the "Add series" "dialogue"
    When I click on "Save series" "button" in the "Add series" "dialogue"
    Then "Add series" "dialogue" should not exist
    And I should see "Courses" in the "[data-region='aic-series']" "css_element"
    And I should see "1 rows returned" in the "[data-region='aic-series']" "css_element"
    When I press "Next"
    Then I should see "Step 3 of 5"

  Scenario: Ask the assistant for a series and save it
    Given the following config values are set as admin:
      | clienttype | stub | local_aicharts |
    And I visit "/local/aicharts/edit.php"
    And I click on "One-shot" "radio"
    And I press "Next"
    When I click on "Add series" "button"
    And I set the field "What should this query return?" in the "Add series" "dialogue" to "tell me a joke"
    And I click on "Ask the assistant" "button" in the "Add series" "dialogue"
    Then I should see "This request is not a chart or report from Moodle data." in the "Add series" "dialogue"
    And the field "SQL" in the "Add series" "dialogue" matches value ""
    When I set the field "What should this query return?" in the "Add series" "dialogue" to "users per course"
    And I click on "Ask the assistant" "button" in the "Add series" "dialogue"
    Then the field "Series name" in the "Add series" "dialogue" matches value "Users per course"
    And the field "SQL" in the "Add series" "dialogue" matches value "SELECT c.fullname AS coursename, COUNT(DISTINCT ue.userid) AS total FROM {course} c JOIN {enrol} e ON e.courseid = c.id JOIN {user_enrolments} ue ON ue.enrolid = e.id WHERE c.id <> :siteid GROUP BY c.fullname ORDER BY c.fullname"
    And I should see "Assistant notes: Counts enrolled users, whatever the enrolment status." in the "Add series" "dialogue"
    When I click on "Run" "button" in the "Add series" "dialogue"
    Then I should see "1 rows returned" in the "Add series" "dialogue"
    When I click on "Save series" "button" in the "Add series" "dialogue"
    Then "Add series" "dialogue" should not exist
    And I should see "Users per course" in the "[data-region='aic-series']" "css_element"
    And I should see "1 rows returned" in the "[data-region='aic-series']" "css_element"

  Scenario: Saving a series after a refused Next stays on the data step
    Given I visit "/local/aicharts/edit.php"
    And I click on "One-shot" "radio"
    And I press "Next"
    When I press "Next"
    Then I should see "Add at least one series."
    When I click on "Add series" "button"
    And I set the following fields in the "Add series" "dialogue" to these values:
      | Series name | Courses                                                                 |
      | SQL         | SELECT c.shortname AS label, c.id AS total FROM {course} c WHERE c.id > :siteid |
      | Parameters  | {"siteid": 1}                                                           |
    And I click on "Run" "button" in the "Add series" "dialogue"
    And I click on "Save series" "button" in the "Add series" "dialogue"
    Then I should see "Step 2 of 5"
    And I should see "Courses" in the "[data-region='aic-series']" "css_element"
    And I should not see "Add at least one series."

  Scenario: Draw the series with the plain controls, ask the assistant and go on to the schedule step
    Given the following config values are set as admin:
      | clienttype | stub | local_aicharts |
    And I visit "/local/aicharts/edit.php"
    And I click on "One-shot" "radio"
    And I press "Next"
    And I click on "Add series" "button"
    And I set the following fields in the "Add series" "dialogue" to these values:
      | Series name | Courses                                                                        |
      | SQL         | SELECT c.fullname AS coursename, c.id AS total FROM {course} c WHERE c.id > :siteid |
      | Parameters  | {"siteid": 1}                                                                  |
    And I click on "Run" "button" in the "Add series" "dialogue"
    And I click on "Save series" "button" in the "Add series" "dialogue"
    When I press "Next"
    Then I should see "Step 3 of 5"
    And I should see "Columns from the Data step: coursename, total"
    And I should see "Bar options"
    And I should not see "Pie options"
    And I should see "1 rows returned" in the "[data-region='aic-preview']" "css_element"
    When I click on "Pie" "radio"
    Then I should see "Pie options"
    And I should not see "Bar options"
    When I set the field "Title" to "My courses"
    And I press "Update preview"
    Then I should see "Step 3 of 5"
    And the field "Title" matches value "My courses"
    And the field "Pie" matches value "1"
    And I should see "1 rows returned" in the "[data-region='aic-preview']" "css_element"
    When I set the field "total" to "0"
    And I set the field "How should it look? (optional)" to "one bar per course"
    And I press "Ask the assistant"
    Then the field "Title" matches value "Users per course"
    And the field "Bar" matches value "1"
    And the field "X axis label" matches value "Course"
    And the field "total" matches value "1"
    When I press "Next"
    Then I should see "Step 4 of 5"

  Scenario: A trend chart is scheduled, never live, and keeps at least thirty points
    Given I visit "/local/aicharts/edit.php"
    And I click on "Trend" "radio"
    And I press "Next"
    And I click on "Add series" "button"
    And I set the following fields in the "Add series" "dialogue" to these values:
      | Series name | Courses                                                        |
      | SQL         | SELECT COUNT(c.id) AS total FROM {course} c WHERE c.id > :siteid |
      | Parameters  | {"siteid": 1}                                                  |
    And I click on "Run" "button" in the "Add series" "dialogue"
    And I click on "Save series" "button" in the "Add series" "dialogue"
    And I press "Next"
    And I press "Next"
    Then I should see "Step 4 of 5"
    And "Live" "radio" should not exist
    And I should see "A trend chart runs on a schedule"
    And I should see "Next run:"
    And I should not see "Day of the week"
    And the field "Points to keep" matches value "365"
    When I click on "Weekly" "radio"
    Then I should see "Day of the week"
    When I set the field "Day of the week" to "Wednesday"
    And I set the field "Points to keep" to "10"
    And I press "Next"
    Then I should see "Step 5 of 5"
    When I click on "Back" "link"
    Then the field "Weekly" matches value "1"
    And the field "Day of the week" matches value "Wednesday"
    And the field "Points to keep" matches value "30"

  Scenario: Build a chart through all five steps and save it
    Given I visit "/local/aicharts/edit.php"
    And I click on "One-shot" "radio"
    And I press "Next"
    And I click on "Add series" "button"
    And I set the following fields in the "Add series" "dialogue" to these values:
      | Series name | Courses                                                                            |
      | SQL         | SELECT c.fullname AS coursename, c.id AS total FROM {course} c WHERE c.id > :siteid |
      | Parameters  | {"siteid": 1}                                                                      |
    And I click on "Run" "button" in the "Add series" "dialogue"
    And I click on "Save series" "button" in the "Add series" "dialogue"
    And I press "Next"
    Then I should see "Step 3 of 5"
    And "input[name='seriescol[1]']" "css_element" should exist
    And "input[name='seriescol[0]']" "css_element" should not exist
    When I set the field "Label column" to "total"
    And I press "Update preview"
    Then I should see "Step 3 of 5"
    And the field "Label column" matches value "total"
    And "input[name='seriescol[0]']" "css_element" should exist
    And "input[name='seriescol[1]']" "css_element" should not exist
    When I set the field "Label column" to "coursename"
    And I press "Update preview"
    Then "input[name='seriescol[0]']" "css_element" should not exist
    When I set the field "total" to "1"
    And I press "Next"
    Then I should see "Step 4 of 5"
    When I click on "Daily" "radio"
    And I press "Next"
    Then I should see "Step 5 of 5"
    And I should see "One-shot — Every run replaces the data."
    And I should see "Courses (1 rows returned"
    And I should see "Scheduled daily at"
    When I set the field "Chart name" to "Courses by id"
    And I press "Save chart"
    Then I should see "Chart saved."
    And I should see "Not run yet."
    And I should see "Courses by id" in the "(//div[@data-region='aic-card'])[1]" "xpath_element"

  Scenario: Edit a saved chart in the wizard
    Given I visit "/local/aicharts/index.php?skiplive=1"
    When I open the action menu in "[data-region=aic-card][data-name='enrolments per course']" "css_element"
    And I choose "Edit" in the open action menu
    Then I should see "Edit chart"
    And I should see "Step 1 of 5"
    And the field "One-shot" matches value "1"
    When I press "Next"
    Then I should see "Step 2 of 5"
    And I should see "Enrolments per course" in the "[data-region='aic-series']" "css_element"
