Feature: To test translations api functions

  Background:
      Given Database is clear
        And The next keys are present in database:
          | key          | bundle       | comment                      |
          | general.test | User         | this is a general test key   |
          | button.save  | Translations | the button to save a message |
        And The next messages are present in database:
          | bundle | key          | language | message        |
          | User   | general.test | en       | general test   |
          | User   | general.test | es       | prueba general |

  Scenario: Get bundle list
       When get bundle index
       Then there are these bundles:
         | bundle       |
         | User         |
         | Translations |

  Scenario: Get key list
       When get key index for bundle "User"
       Then there are these keys:
         | key          | comment                    |
         | general.test | this is a general test key |

  Scenario: Get message list
       When get messages for a key "User:general.test"
       Then there are these messages:
         | language | message        |
         | es       | prueba general |
         | en       | general test   |

  Scenario: Put message key for a existing key/language
       When put message for a key "User:general.test/en" as "another test"
        And get messages for a key "User:general.test"
       Then there are these messages:
         | language | message        |
         | es       | prueba general |
         | en       | another test   |

  Scenario: Put message key for a existing key, non existing language
       When put message for a key "User:general.test/it" as "prova"
        And get messages for a key "User:general.test"
       Then there are these messages:
         | language | message        |
         | es       | prueba general |
         | en       | general test   |
         | it       | prova          |

  Scenario: Put message key for a non existing key
       When put message for a key "User:general.area/en" as "general area"
        And get messages for a key "User:general.area"
       Then there are these messages:
         | language | message        |
         | en       | general area   |

  Scenario: Update message key if newest for a key newest
      When update message for a key "User:general.test/en" as "another test","newest"
       And get messages for a key "User:general.test"
      Then there are these messages:
         | language | message        |
         | es       | prueba general |
         | en       | another test   |


  Scenario: Update message key if newest for a key oldest
       When update message for a key "User:general.test/en" as "another test","oldest"
        And get messages for a key "User:general.test"
       Then there are these messages:
         | language | message        |
         | es       | prueba general |
         | en       | general test   |

