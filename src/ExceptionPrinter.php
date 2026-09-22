<?php

namespace Ascension;

class ExceptionPrinter
{
    public function __construct($message, int $statusCode = 500)
    {
        http_response_code($statusCode);

        if (isset($_SERVER['CONTENT_TYPE']) && strtolower($_SERVER['CONTENT_TYPE']) == 'application/json') {
            echo json_encode(array(
                'Exception' => $message
            ));
            exit();
        } else {
            $html = '<!DOCTYPE html>
<html>
<head>
    <title>Ascension::Core Exception Raised</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
            text-align: center;
        }

        .container {

            padding: 10px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.2);
            width: 90%;
            position: absolute;
            top: 50%;
            left: 50%;
            height:70vh;
            transform: translate(-50%, -50%);
        }

        .container-left {
            float:left;
            width:20%;
        }

        .container-right {
            float:right;
            width:80%;
        }

        .robot {
            font-family: monospace;
            font-size: 24px;
        }

        pre.exception {
        max-height:620px;
            min-height:620px;
            text-align:left;
            font-family: Courier;
            background-color: #e0e0e0;
            overflow-y: scroll;
        }
    </style>
</head>
<body>
    <div class="container">
    <div class="container-left">
        <div class="robot">
            <pre>
  _____  ___ ____
 | ____|/ _ \___ \
 | |__ | | | |__) |
 |___ \| | | |__ <
  ___) | |_| |__) |
 |____/ \___/____/

EXCEPTION RAISED
            </pre>
        </div>
        </div>

        <div class="container-right">

             <pre class="exception">' . htmlspecialchars($message, ENT_QUOTES) . '</pre>

        </div>
    </div>

</body>
</html>';

            echo $html;
        }
    }
}
