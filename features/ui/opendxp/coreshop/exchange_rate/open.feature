@ui @ui_opendxp @exchange_rate
Feature: Test if I can open the Exchange Rates Panel
    Scenario:
        Given I am a logged in admin
        And I open OpenDxp
        And I open resource "coreshop.currency", "exchange_rate"
        Then exchange-rates tab is open
