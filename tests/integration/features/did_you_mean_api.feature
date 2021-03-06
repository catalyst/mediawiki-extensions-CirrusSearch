@clean @api @did_you_mean
Feature: Did you mean
  Scenario: Uncommon phrases spelled correctly don't get suggestions even if one of the words is very uncommon
    When I api search for nobel prize
    Then there are no did you mean suggestions from the api

  Scenario: No suggestions on pages that are not the first
    When I api search with offset 20 for popular cultur
    Then there are no did you mean suggestions from the api

  @stemming
  Scenario: Suggestions do not show up when a full title matches but with stemming
    When I api search for stemmingsingleword
    Then there are no did you mean suggestions from the api

  @stemming
  Scenario: Suggestions do not show up when a full multi word title matches but with stemming
    When I api search for stemming multiword
    Then there are no did you mean suggestions from the api

  @stemming
  Scenario: Suggestions do not show up when a full multi word title matches but with apostrophe normalization
    When I api search for stemming possessive's
    Then there are no did you mean suggestions from the api

  Scenario: Suggestions don't come from redirect titles when it matches an actual title
    When I api search for Noble Gasses
    Then there are no did you mean suggestions from the api

  Scenario: Common phrases spelled incorrectly get suggestions
    When I api search for popular cultur
    Then popular *culture* is the did you mean suggestion from the api

  Scenario Outline: Uncommon phrases spelled incorrectly get suggestions even if the typos is in the first 2 characters
    When I api search for <term>
    Then <suggested> is the did you mean suggestion from the api
  Examples:
    |                    term                   |                  suggested                  |
    | nabel prize                               | *nobel* prize                               |
    | onbel prize                               | *nobel* prize                               |

  Scenario: Uncommon phrases spelled incorrectly get suggestions even if they contain words that are spelled correctly on their own
    When I api search for noble prize
    Then *nobel* prize is the did you mean suggestion from the api

  Scenario: Suggestions can come from redirect titles when redirects are included in search
    When I api search for Rrr Worrd
    Then rrr *word* is the did you mean suggestion from the api

  Scenario Outline: Special search syntax is preserved in suggestions (though sometimes moved around)
    When I api search for <term>
    Then <suggested> is the did you mean suggestion from the api
  Examples:
    |                    term                   |                  suggested                  |
    | prefer-recent:noble prize                 | prefer-recent:*nobel* prize                 |
    | prefer-recent:noble prize                 | prefer-recent:*nobel* prize                 |
    | incategory:prize noble prize              | incategory:prize *nobel* prize              |
    | noble incategory:prize prize              | incategory:prize *nobel* prize              |
    | hastemplate:prize noble prize             | hastemplate:prize *nobel* prize             |
    | -hastemplate:prize noble prize            | -hastemplate:prize *nobel* prize            |
    | boost-templates:"prize\|150%" noble prize | boost-templates:"prize\|150%" *nobel* prize |
    | noble prize prefix:n                      | *nobel* prize prefix:n                      |

  Scenario: Customize prefix length of did you mean suggestions
    When I set did you mean suggester option cirrusSuggPrefixLength to 5
    And I api search for noble prize
    Then there are no did you mean suggestions from the api

  Scenario: Customize prefix length of did you mean suggestions below the hard limit
    When I reset did you mean suggester options
    And I set did you mean suggester option cirrusSuggPrefixLength to 1
    And I api search for nabol prize
  Then there are no did you mean suggestions from the api

  Scenario: Disable the reverse field
    When I reset did you mean suggester options
    And I set did you mean suggester option cirrusSuggUseReverse to no
    And I api search for nabel prize
    Then there are no did you mean suggestions from the api

  Scenario: When I use the collate option: awards suggest1 suggest4 returns no suggestion
    When I set did you mean suggester option cirrusSuggCollate to yes
    And I api search for awards suggest1 suggest4
    Then there are no did you mean suggestions from the api
