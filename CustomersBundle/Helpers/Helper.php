<?php
/**
 * Created by PhpStorm.
 * User: sromanenko
 * Date: 10.08.18
 * Time: 12:15
 */

namespace CustomersBundle\Helpers;

use GuzzleHttp\Client as HttpClient;
use Twig_Loader_Filesystem;
use Twig_Environment;

class Helper {

    /**
     * @param $accountId
     * @return int
     */
    public static function getAccountShardType($accountId) {
        $http = new HttpClient([
            'base_url' => 'https://www.amocrm.ru',
        ]);
        $request = [
            'id' => $accountId
        ];
        $response = $http->post('/private/accounts/id.php',[
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'=> $request,
        ]);

        $response = json_decode($response->getBody(), true);
        $response = reset($response);
        if (!isset($response['shard_type'])) {
            throw new \RuntimeException('Can\'t get shard_type. Response: ' . var_export($response, TRUE));
        }

        return (int)$response['shard_type'];
    }

    /**
     * @param string $view - name of template
     * @param array $vars
     * @return string
     */
    static public function render($view, $vars = []) {
        $loader = new Twig_Loader_Filesystem( __DIR__ . '/../templates');
        $twig = new Twig_Environment($loader);
        $template = $twig->load($view);
        return $template->render($vars);
    }
}