@ui @ui_opendxp @login
Feature: Signing in to the OpenDxp Backend

    Scenario: Sign in with email and password
        When I want to log into OpenDxp backend
        And I specify the username as "admin"
        And I specify the password as "coreshop"
        And I log in
        Then I should be logged in
