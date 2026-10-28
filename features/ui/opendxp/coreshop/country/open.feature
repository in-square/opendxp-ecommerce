@ui @ui_opendxp @country @wip
Feature: Test if I can open the Countries Panel
    Scenario:
        Given I am a logged in admin
        And I open OpenDxp
        And I open the countries tab
        Then countries tab is open
