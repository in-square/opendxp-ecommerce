@ui @ui_opendxp @tax_rule
Feature: Test if I can open the Tax Rule Groups Panel
    Scenario:
        Given I am a logged in admin
        And I open OpenDxp
        And I open resource "coreshop.taxation", "tax_rule_group"
        Then tax-rule-groups tab is open
