@domain @opendxp
Feature: Adding a new opendxp brick

  Scenario: Create a new opendxp brick
    Given there is a opendxp brick "Attributes"
    Then there should be a opendxp brick "Attributes"

  Scenario: Create a new opendxp brick with a input field
    Given there is a opendxp brick "Attributes"
    And the definition has a input field "name"
    Then the definition should have a field named "name"
