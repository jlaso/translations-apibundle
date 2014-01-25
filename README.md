========
Overview
========

This bundle permits API comunication with http://translations.com.es

Installation
------------
Checkout a copy of the code::

    // in composer.json
    "require": {
        // ...
        "jlaso/translations-apibundle": "*"
        // ...
    },


Then register the bundle with your kernel::

    // in AppKernel::registerBundles()
    $bundles = array(
        // ...
        new JLaso\TranslationsApiBundle\TranslationsApiBundle(),
        // ...
    );


Configuration
-------------
::


    // in app/config/parameters.yml
    ###############################
    ##   TRANSLATIONS API REST   ##
    ###############################
    jlaso_translations_api_access:
        project_id: 1 # the number that correspond to the project created
        key:  1234  # the key that systems assigns
        secret: 1234  # the password that you choose when init project in server
        url: http://translations.com.es/app.php/api/


    // in app/config/config.yml
    translations_api:
        default_locale: %locale%
        managed_locales: ['es', 'en', 'fr', 'ca']  # the languages you want

    and remember to enable translator in framework key
    framework:
        translator:      { fallback: %locale% }


Usage
-----
first schema:update to init database with SCM table:
::
    app/console doctrine:schema:update --force --env=dev


Examples
--------
For synchronize local translations with server (remote) translations:
::
    app/console jlaso:translations:sync [--cache-clear] [--backup-files]

In the view
::
    Currently only be used yaml files in src/Company/xxBundle/Resource/translations/messages.xx.yml
    with xx as locale, and comments for remarks to help translations in web interface,
    this is src/Company/xxBundle/Resource/translations/messages.comments.yml
    same for translations resources of app located at app/Resources/messages.xx.yml

