@domain @opendxp
Feature: Adding a new opendxp collection

  Scenario: Create a new opendxp fieldcollection
    Given there is a opendxp field-collection "Colors"
    Then there should be a opendxp field-collection "Colors"

  Scenario: Create a new opendxp field-collection with a input field
    Given there is a opendxp field-collection "Attributes"
    And the definition has a input field "name"
    Then the definition should have a field named "name"
