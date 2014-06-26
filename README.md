[![Latest Stable Version](https://poser.pugx.org/jlaso/translations-apibundle/v/stable.png)](https://packagist.org/packages/jlaso/translations-apibundle)
[![Total Downloads](https://poser.pugx.org/jlaso/translations-apibundle/downloads.png)](https://packagist.org/packages/jlaso/translations-apibundle)

========
Overview
========

This bundle permits API comunication with https://translations.com.es

In order to install this bundle you need to pay attention with requiremens: 

    php > 5.3
    php-lzf extension must be installed (try sudo pecl install lzf)


Installation
------------
Checkout a copy of the code:

    // in composer.json
    "require": {
        // ...
        "jlaso/translations-apibundle": "*"
        // ...
    },


Then register the bundle with your kernel:

    // in AppKernel::registerBundles()
    $bundles = array(
        // ...
        new JLaso\TranslationsApiBundle\TranslationsApiBundle(),
        // Excel Bundle
        new Liuggio\ExcelBundle\LiuggioExcelBundle(),
        // ...
    );


Configuration
-------------


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

    app/console doctrine:schema:update --force --env=dev
    
and next upload your messages to remote server

    app/console jlaso:translations:sync --upload-first=yes
    when you use the bundle first time is necessary the use the upload-first option in order to generate the remote db

Examples
--------
For synchronize local translations with server (remote) translations:

    app/console jlaso:translations:sync [--cache-clear] [--backup-files]

In the view

    Currently only be used yaml files in src/Company/bbbBundle/Resource/translations/messages.xx.yml
    with xx as locale, and bbb as bundle name,
    same for translations resources of app located at app/Resources/messages.xx.yml

