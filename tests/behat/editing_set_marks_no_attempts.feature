@mod @mod_quizp
Feature: In order to create a quizp that awards marks the way I want
  As a teacher
  I must be able to set the marks I want on the Edit quizp page.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | T1        | Teacher1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name   | course | idnumber | grade | decimalpoints | questiondecimalpoints |
      | quizp       | Quiz 1 | C1     | quizp1    | 20    | 2             | -1                    |
    And I log in as "teacher1"
    And I follow "Course 1"
    And I add a "True/False" question to the "Quiz 1" quizp with:
      | Question name | First question |
      | Question text | Answer me      |
      | Default mark  | 2.0            |
    And I add a "True/False" question to the "Quiz 1" quizp with:
      | Question name | Second question |
      | Question text | Answer again    |
      | Default mark  | 3.0             |

  @javascript
  Scenario: Set the max mark for a question.
    When I set the max mark for question "First question" to "7.0"
    Then I should see "7.00"
    And I should see "3.00"
    And I should see "Total of marks: 10.00"

    When I follow "Edit maximum mark"
    And I wait until "li input[name=maxmark]" "css_element" exists
    And I take focus off "li input[name=maxmark]" "css_element"
    Then I should see "7.00"
    And I should see "3.00"
    And I should see "Total of marks: 10.00"
    And "li input[name=maxmark]" "css_element" should not exist

  @javascript
  Scenario: Set the overall Maximum grade.
    When I set the field "maxgrade" to "10.0"
    And I press "savechanges"
    Then the field "maxgrade" matches value "10.00"
    And I should see "2.00"
    And I should see "3.00"
    And I should see "Total of marks: 5.00"

  @javascript
  Scenario: Verify the number of decimal places shown is what the quizp settings say it should be.
    # Then the field "maxgrade" matches value "20.00" -- with exact match on decimal places.
    Then "//input[@name = 'maxgrade' and @value = '20.00']" "xpath_element" should exist
    And I should see "2.00"
    And I should see "3.00"
    And I should see "Total of marks: 5.00"
    And I should not see "2.000"
    And I should not see "3.000"
    And I should not see "Total of marks: 5.000"
    When I follow "Edit settings"
    And I set the following fields to these values:
      | Decimal places in grades | 3 |
      | Decimal places in question grades | 5 |
    And I press "Save and display"
    And I follow "Edit quizp"
    # Then the field "maxgrade" matches value "20.000" -- with exact match on decimal places.
    Then "//input[@name = 'maxgrade' and @value = '20.000']" "xpath_element" should exist
    And I should see "2.00000"
    And I should see "3.00000"
    And I should see "Total of marks: 5.000"
    And I should not see "2.000000"
    And I should not see "3.000000"
    And I should not see "Total of marks: 5.0000"