# Boodil Open Banking Gateway
# Magento 2 - Boodil Payment
- Goto: STORES > Configuration > SALES > Payment Methods > Boodil Payment Method
- It works for both Guest and Logged In customers.

## **Prerequisite**
- Composer: 2.x
- PHP: 7.4, 8.0, 8.1
- Magento: 2.3,2.4

## **Installation** 
1. Composer Installation
      - Navigate to your Magento root folder<br />
            `cd path_to_the_magento_root_directory`
      - Then run the following command<br />
            `composer require limelyltd/boodil`<br />
      - Make sure that composer finished the installation without errors

 2. Command Line Installation
      - Backup your web directory and database.
      - Download the latest installation package `Source code (zip)` from [here](https://github.com/Limely/boodil/releases/)
      - Navigate to your Magento root folder<br />
            `cd path_to_the_magento_root_directory`<br />
      - Upload contents of the installation package to your Magento root directory
      - Then run the following command<br />
            `php bin/magento module:enable Boodil_Payment`<br />

- After install the extension, run the following command
```
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:flush
```
- Log out from the backend and login again.
