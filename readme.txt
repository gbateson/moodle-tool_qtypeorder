=======================
moodle-tool_qtypeorder
=======================

Moodle 2.x admin tool to migrate qtype_order questions to qtype_ordering

=======================
Requirements
=======================

(1) Moodle >= 2.2
(2) OLD Ordering question type: qtype_order
(3) NEW Ordering question type: qtype_ordering

=======================
Installation
=======================

To install using GIT ...

    ## change to Moodle scripts dir
    cd /path/to/moodle

    ## GIT clone into "admin/tool/qtypeorder"
    git clone -q -b master https://github.com/gbateson/moodle-tool_qtypeorder.git admin/tool/qtypeorder

    ## login to Moodle as admin
    ## to complete the installation

To install using ZIP ...

    ## navigate to Moodle scripts dir
    ## Note: you will need to change this path
    ##       to what is required on your server
    cd /path/to/moodle

    ## navigate to "admin/tool" dir
    cd admin/tool

    ## fetch zip file from github.com
    ## Note: if you do not have wget,
    ##       you may be able to use the "curl" command
    ##       or download the zip file to your PC and
    ##       then use FTP to upload it to the server
    wget --no-check-certificate -O master.zip https://github.com/gbateson/moodle-tool_qtypeorder/archive/master.zip

    ## unzip the zip file
    unzip master.zip

    ## rename the unzipped dir
    mv 'moodle-tool_qtypeorder-master' qtypeorder

    ## remove the zip file
    unzip master.zip

    ## login to Moodle as admin
    ## to complete the installation

=======================
Usage
=======================

(1) login to your Moodle site as administrator
(2) navigate to: Administration -> Site administration -> Plugins -> Admin tools -> Migrate Ordering questions
(3) Set "Confirm" to "Yes"
(4) click the "Go" button

When all the OLD Ordering questions have been converted to NEW Ordering questions,
and there are no OLD Ordering questions left, the OLD Ordering question plugin,
"qtype_order", can be uninstalled.

=======================
Acknowledgements
=======================

Many thanks to Prof. Don Hinkelman, who commissioned this tool,
and Sapporo Gakuin University, who sponsored the programming.