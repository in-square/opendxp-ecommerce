@ui @ui_opendxp @currency
Feature: Test if I can open the Currencies Panel
    Scenario:
        Given I am a logged in admin
        And I open OpenDxp
        And I open resource "coreshop.currency", "currency"
        Then currencies tab is open
