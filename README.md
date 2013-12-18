Shopatron Online Order Processing Tool for Magento
=========================================

NOTE: This was written for and tested on Magento 1.7

This requires XML-RPC for PHP (specifically version 3.0.0), located at http://phpxmlrpc.sourceforge.net/

This repo can be uploaded into your Magento 1.7 installation to integrate Shopatron's Online Order Processing Tool. 
I ask that if this code is used, I would like to see it in action and get any feedback you might have. Feel free to contribute. 

NOTE: This is a barebones project. Your project may require additional code to be written. Does not include tax information.


Installation:
=============

1. Edit Observer.php to reflect your manufacturer ID, catalog ID, and $_domain.
2. (Optional) Set $_sDebug to true.
3. Install files to your core Magento installation folder. 
4. Refresh your cache.
5. Your customers will now be redirected when checking out. Do not forget to set $_sDebug to false.
