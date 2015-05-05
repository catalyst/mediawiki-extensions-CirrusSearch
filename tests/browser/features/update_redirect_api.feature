@clean @api @redirect @update
Feature: Updating a page from or to a redirect
  Scenario: Turning a page into a redirect removes it from the search index
    Given a page named RedirectTaget exists
    When a page named ToBeRedirect%{epoch} exists
    Then within 20 seconds api searching for ToBeRedirect%{epoch} yields ToBeRedirect%{epoch} as the first result
    When a page named ToBeRedirect%{epoch} exists with contents #REDIRECT [[RedirectTaget]]
    Then within 20 seconds api searching for ToBeRedirect%{epoch} yields RedirectTaget as the first result
      And ToBeRedirect%{epoch} is not in the api search results

  Scenario: Turning a page from a redirect to a regular page puts it in the index
    Given a page named RedirectTaget exists
    When a page named StartsAsRedirect%{epoch} exists with contents #REDIRECT [[RedirectTaget]]
    Then within 20 seconds api searching for StartsAsRedirect%{epoch} yields RedirectTaget as the first result
    When a page named StartsAsRedirect%{epoch} exists
    Then within 20 seconds api searching for StartsAsRedirect%{epoch} yields StartsAsRedirect%{epoch} as the first result
      And RedirectTarget is not in the api search results
