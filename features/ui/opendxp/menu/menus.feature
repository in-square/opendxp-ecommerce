@ui @ui_opendxp @menu
Feature: Test if I can open a menu in OpenDxp
    Background: Sign in with email and password
        Given I want to log into OpenDxp backend
        And I specify the username as "admin"
        And I specify the password as "coreshop"
        And I log in
        Then I should be logged in

    Scenario:
        Then There should be a menu File
        Then There should be a menu Tools
        Then There should be a menu Marketing
        Then There should be a menu Settings
        Then There should be a menu Search
        Then There should be a menu CoreShop

