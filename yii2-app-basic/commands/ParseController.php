<?php

namespace app\commands;

use Google_Service_Sheets;
use Yii;
use yii\console\Controller;
use function PHPUnit\Framework\isEmpty;

class ParseController extends Controller
{

    public function actionGetSheet($sheet_id){
        $client = new \Google_Client();
        $client->setApplicationName('spreadsheet parse');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        $service = new Google_Service_Sheets($client);
        $spreadsheetId = $sheet_id;
        $get_range = "MA!A4:N109";
        $response = $service->spreadsheets_values->get($spreadsheetId, $get_range);
        return $response->getValues();
    }

    public function actionParser($sheet_id){
        $table = $this->actionGetSheet($sheet_id);
        $file = 'commands/data.txt';
        $data = [];
        $diffs = [];
        if (file_exists($file)) {
            $local_data = json_decode(file_get_contents($file), true);
        }
        $exceptions = ["Internet Total","PVR on Internet"];
        $lastService = "";
        $lastCategory = "";

        for($i = 0; $i < count($table); $i++) {
            $expenses = [];
            for($j = 0; $j < count($table[$i]); $j++) {
                $cell_value = $table[$i][$j];
                if($j == 0){
                    if($i == 0){
                        $lastCategory = $cell_value;
                        $data[$lastCategory] = [];
                        $lastService = $table[$i+1][$j];

                        if(isset($local_data) && !key_exists($lastCategory, $local_data)){
                            //______________________________________
                            $diffs[] = [1, $lastCategory];
                        }
                        if(isset($local_data) && !key_exists($lastService, $local_data[$lastCategory])){
                            //______________________________________
                            $diffs[] = [2, [$lastCategory, $lastService]];
                        }
                    }
                    else if (isset($table[$i+1][$j]) && $cell_value == 'Total' ){
                        while(in_array($table[$i+1][$j], $exceptions)){
                            $i++;
                        }
                        if($table[$i+1][$j] == 'CO-OP'){
                            break 2;
                        }
                        $category_name = $table[$i+1][$j];
                        $data[$category_name] = [];
                        $lastCategory = $category_name;

                        if(isset($local_data) && !key_exists($lastCategory, $local_data)){
                            //______________________________________
                            $diffs[] = [1, $lastCategory];
                        }

                        $i++;
                    }
                    else if ($cell_value != ''){
                        $lastService = $cell_value;
                        $data[$lastCategory][$lastService] = [];

                        if(isset($local_data) && !key_exists($lastService, $local_data[$lastCategory])){
                            //______________________________________
                            $diffs[] = [2, [$lastCategory, $lastService]];
                        }
                    }
                }

                if($j > 0 && $j < 13 ){
                    if(isset($cell_value) && $cell_value != ''){
                        $expenses[$j] = $cell_value;
                        $data[$lastCategory][$lastService]["expenses"] = $expenses;

                        if(isset($local_data[$lastCategory][$lastService]["expenses"][$j]) ){
                            $compare_value = $local_data[$lastCategory][$lastService]["expenses"][$j];
                        }else{
                            $compare_value = NULL;
                        }

                        if(isset($local_data) && $compare_value != $data[$lastCategory][$lastService]["expenses"][$j] )
                        {
                            //______________________________________
                            $sum = str_replace("$", '', $cell_value);
                            $diffs[] = [3,[$lastCategory, $lastService, $j, $sum]];
                        }

                    }
                }
            }
        }

        return [$data,$diffs];
    }

    public function actionIndex($sheet_id)
    {
        $filename = "commands/data.txt";
        $request = $this->actionParser($sheet_id);
        $data = $request[0];
        $diffs = $request[1];

        $this->actionCreateTables();

        if(empty($diffs)){
            if (file_exists($filename)) {
                echo "Data is actual";
            }else{
                file_put_contents($filename, '');
                file_put_contents($filename, json_encode($data));
                $this->actionUpload($data);
                echo "Data is successfully inserted";
            }
        }else{
            file_put_contents($filename, '');
            file_put_contents($filename, json_encode($data));
            $this->actionUpdate($diffs);
        }
    }

    public function actionUpdate($diffs){
        $db = Yii::$app->db;
        foreach ($diffs as $diff){
            if($diff[0] == 1){
                $db->createCommand()->insert('categories', ['name' => $diff[1],])
                    ->execute();
            }
            if($diff[0] == 2){

                $request = $db->createCommand('SELECT id FROM categories WHERE name=:name')
                    ->bindValue(':name', $diff[1][0])
                    ->queryOne();

                $category_id = $request["id"];

                $db->createCommand()->insert('services', [
                    'name' => $diff[1][1],
                    'category_id' => $category_id])
                    ->execute();
            }
            if($diff[0] == 3){
                $request = $db->createCommand('SELECT id FROM services WHERE name=:name')
                    ->bindValue(':name', $diff[1][1])
                    ->queryOne();

                $service_id = $request["id"];
                $db->createCommand('UPDATE expenses SET sum=:sum WHERE service_id=:service_id AND month=:month')
                    ->bindValue(':month', $diff[1][2])
                    ->bindValue(':sum', $diff[1][3])
                    ->bindValue(':service_id', $service_id)
                    ->execute();
                echo "Sum is updated [".$diff[1][0]. " - " .$diff[1][1]."] by value:".$diff[1][3]."\n";
            }
            //print_r($diff);
        }
    }

    public function actionUpload($data){

        $db = Yii::$app->db;
        //$fileName = "commands/data.txt";
        //$data = json_decode(file_get_contents($fileName), true);

        $db_categories = $db->createCommand('SELECT * FROM categories')
            ->queryAll();

        if(empty($db_categories)){
            echo "Categories is empty. Inserting all data...\n";

            foreach ($data as $category_key => $category){
                $category_name = $category_key;

                $db->createCommand()->insert('categories', ['name' => $category_name,])
                    ->execute();

                $request = $db->createCommand('SELECT id FROM categories WHERE name=:name')
                    ->bindValue(':name', $category_name)
                    ->queryOne();

                $category_id = $request["id"];

                foreach ($category as $service_name => $service){

                    $db->createCommand()->insert('services', [
                        'name' => $service_name,
                        'category_id' => $category_id])
                        ->execute();

                    foreach($service as $expense){
                        foreach ($expense as $month => $sum){

                            $request = $db->createCommand('SELECT id FROM services WHERE name=:name')
                                ->bindValue(':name', $service_name)
                                ->queryOne();

                            $service_id = $request["id"];

                            $sum = str_replace("$", '', $sum);

                            $db->createCommand()->insert('expenses', [
                                'month' => $month,
                                'sum' => $sum,
                                'service_id' => $service_id])
                                ->execute();
                        }
                    }

                }
            }
        }
        else{
            //print_r($db_categories);
        }
    }

    public function actionCreateTables(){
        $db = Yii::$app->db;

        $db->createCommand('CREATE TABLE IF NOT EXISTS `categories` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `name` varchar(128) NOT NULL,
                                  PRIMARY KEY (`id`)
                                ) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8')
            ->execute();

        $db->createCommand('CREATE TABLE IF NOT EXISTS `services` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `name` varchar(128) NOT NULL,
                                  `category_id` int(11) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  KEY `category_service_id` (`category_id`),
                                  CONSTRAINT `services_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
                                ) ENGINE=InnoDB AUTO_INCREMENT=318 DEFAULT CHARSET=utf8')
            ->execute();

        $db->createCommand('CREATE TABLE IF NOT EXISTS `expenses` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `month` int(11) NOT NULL,
              `sum` varchar(32) NOT NULL,
              `service_id` int(11) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `service_id` (`service_id`),
              CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`)
            ) ENGINE=InnoDB AUTO_INCREMENT=824 DEFAULT CHARSET=utf8')
            ->execute();
    }

    public function actionTest(){
        $this->actionCreateTables();
    }

}