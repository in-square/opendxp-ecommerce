@ui @ui_opendxp @menu
Feature: Count CoreShop Menu Items

    Background: Sign in with email and password
        Given I log into the OpenDxp backend

    Scenario:
        Given I open the CoreShop menu
        Then the opened Menu should have 14 items
