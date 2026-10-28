@domain @opendxp
Feature: Adding a new opendxp Class

  Scenario: Create a new opendxp class
    Given there is a opendxp class "Car"
    And the definitions parent class is set to "\CoreShop\Behat\Model\Car"
    And the class "Car" is registered as OpenDxp Resource
