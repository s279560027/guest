<?php

namespace XOApp;


class XOServer
{
    private $method;
    private $response;
    public static $db;

    const GAME_TIMEOUT = 600;
    const STEP_TIMEOUT = 60;

    const DB_HOST = 'localhost';
    const DB_NAME = 'game';
    const DB_USER = 'root';
    const DB_PASS = '';

    const ERROR_UNEXPECTED = 1;
    const ERROR_NO_METHOD_EXIST = 2;
    const ERROR_GAME_NO_FILLED = 3;
    const ERROR_GAME_CLOSED = 4;
    const ERROR_GAME_CREATE = 5;
    const ERROR_GAME_NOT_EXIST = 6;
    const ERROR_WRONG_MOVE_SYMBOL = 7;
    const ERROR_INVALID_MOVE = 8;
    const ERROR_PARAM_NOT_PASSED = 9;

    private static $instance = null;

    private function __clone()
    {
    }

    private function __construct()
    {
    }


    public static function start()
    {
        if (null === self::$instance) {
            self::$instance = new self();

            try {
                self::$instance->init();
                self::$instance->doAction();
            } catch (PDOException $pdoException) {
                throw new XOGameException(self::ERROR_UNEXPECTED);
            } catch (XOGameException $e) {
                self::$instance->response = array('error' => $e->getMessage(), 'errorCode' => $e->getCode());
            }
        }
    }

    private function init()
    {
        $this->setConfig();
        $this->setRoute();
        $this->checkGames();
    }

    private function getParam($paramName)
    {
        if (!isset($_REQUEST[$paramName])) {
            throw new XOGameException(self::ERROR_PARAM_NOT_PASSED, $paramName);
        }
        return $_REQUEST[$paramName];
    }

    private function setRoute()
    {
        $this->method = 'action' . ucfirst($this->getParam('method'));
        if (!method_exists(__CLASS__, $this->method)) {
            throw new XOGameException(self::ERROR_NO_METHOD_EXIST);
        }
    }

    private function setConfig()
    {
        session_start();
        self::$db = new \PDO(sprintf("mysql:host=%s;dbname=%s", self::DB_HOST, self::DB_NAME), self::DB_USER, self::DB_PASS);
    }

    private function checkGames()
    {
        $stmt = self::$db->prepare("UPDATE game SET closed = 1 WHERE id IN
          (SElECT id FROM 
            (SELECT id FROM game WHERE (filled = 0 AND lastping < ?) OR (filled = 1 AND lastping < ?)
          ) g)");
        $stmt->execute(array(time() - XOServer::GAME_TIMEOUT, time() - XOServer::STEP_TIMEOUT));


    }

    private function actionStart()
    {
        $game = new XOGame();

        $human = $this->getParam('human');

        $game->setParam('human', $human);
        $game->setParam('filled', $human ? 0 : 1);
        $game->create();

        $symbol = $game->getParam('x_session') === session_id() ? 'x' : 'o';

        return array(
            'id' => $game->getParam('id'),
            'human' => $game->getParam('human'),
            'symbol' => $symbol
        );
    }

    private function actionFind()
    {
        $stmt = self::$db->prepare("SELECT id FROM game WHERE human = 1 AND closed = 0 AND filled != 1");
        $stmt->execute(array($this->GAME_TIMEOUT));
        $id = $stmt->fetch(\PDO::FETCH_ASSOC)['id'];

        if (!$id) {
            return new \stdClass();
        }

        $game = new XOGame($id);

        $oppositeSymbol = $game->getParam('x_session') ? 'o' : 'x';
        $oppositeSessionName = $oppositeSymbol . '_session';

        $game->setParam('lastping', time());
        $game->setParam('filled', 1);
        $game->setParam($oppositeSessionName, session_id());
        $game->update();


        return array(
            'id' => $id,
            'symbol' => $oppositeSymbol
        );

    }

    private function actionState()
    {

        $id = $this->getParam('id');
        $game = new XOGame($id);

        if (!$game->getParam('id')) {
            throw new XOGameException(self::ERROR_GAME_NOT_EXIST);
        }

        $output = array(
            'state' => $game->viewState()
        );
        if (!$game->getParam('closed')) {
            $output['timetoclose'] = $game->getTimeToClose();
        }
        if ($game->getParam('human')) {
            $output['filled'] = $game->getParam('filled');
        }
        if ($game->getParam('filled') && !$game->getParam('closed')) {
            $output['expected'] = $game->getExpected();
        }
        if ($game->getTimeToClose() == 0) {

        }

        return $output;

    }

    private function actionMove()
    {

        $id = $this->getParam('id');
        $x = $this->getParam('x');
        $y = $this->getParam('y');

        $game = new XOGame($id);

        if (!$game->getParam('filled')) {
            throw new XOGameException(self::ERROR_GAME_NO_FILLED);
        }

        if ($game->getParam('closed')) {
            throw new XOGameException(self::ERROR_GAME_CLOSED);
        }

        $expectedSymbol = $game->getExpected();
        if ($game->getParam($expectedSymbol . '_session') !== session_id()) {
            throw new XOGameException(self::ERROR_WRONG_MOVE_SYMBOL);
        }

        $moveResult = $game->setMove($x, $y);

        if (!$game->getParam('human') && $moveResult == -1) {
            $moveResult = $game->setCompMove();
        }

        $game->setParam('lastping', time());
        $game->update();

        $output = array(
            'state' => $game->viewState()
        );
        if ($moveResult === -1) {
            $output['expected'] = $game->getExpected();
            $output['timetoclose'] = $game->getTimeToClose();
        }
        if ($moveResult > -1) {
            $output['end'] = 1;
        }
        if ($moveResult > 0) {
            $output['win'] = $moveResult == 1 ? 'x' : 'o';
        }

        return $output;
    }


    public static function sendResponse()
    {
        header('Content-Type: application/json');
        echo json_encode(self::$instance->response);
    }

    private function doAction()
    {
        $this->response = call_user_func(array(__CLASS__, $this->method));
    }


}


class XOGame
{

    private $params = array();
    private $state;


    public function setParam($name, $param)
    {
        switch ($name) {
            case 'closed':
            case 'filled':
            case 'human':
                $this->params[$name] = $param ? 1 : 0;
                break;
            case 'lastping':
                $this->params[$name] = intval($param);
                break;
            case 'x_session':
            case 'o_session':
                $this->params[$name] = $param;
                break;
        }

        return $this;
    }


    public function viewState()
    {
        return array_chunk($this->state, 3);
    }

    public function getParam($name)
    {
        if (!isset($this->params[$name])) {
            return false;
        }
        return $this->params[$name];
    }

    public function getExpected()
    {
        return count(array_filter($this->state)) % 2 == 0 ? 'x' : 'o';
    }

    public function getTimeToClose()
    {
        $timeout = $this->params['filled'] ? XOServer::STEP_TIMEOUT : XOServer::GAME_TIMEOUT;

        $timetoclose = $timeout - time() + $this->params['lastping'];
        if ($timetoclose < 1) {
            $timetoclose = 0;
        }
        return $timetoclose;
    }

    public function __construct($id = 0)
    {
        if (!$id) {
            return $this;
        }
        $stmt = XOServer::$db->prepare("SELECT * FROM game WHERE id = ?");
        $stmt->execute(array($id));
        $this->params = $stmt->fetch(\PDO::FETCH_ASSOC);

        $stmt = XOServer::$db->prepare("SELECT x + y * 3 `key`, `type` FROM move WHERE game_id = ? ORDER BY x, y");
        $stmt->execute(array($id));
        $this->state = array_replace(array_fill(0, 9, ''), $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));

        return $this;
    }

    public function update()
    {
        if ($this->params['id']) {
            $params = $this->params;
            unset($params['id']);
            $paramsPlaceholer = '';
            $values = array();
            foreach ($params as $key => $value) {
                $paramsPlaceholer .= "{$key} = ?,";
                $values[] = $value;
            }
            $paramsPlaceholer = trim($paramsPlaceholer, ',');
            $values[] = $this->params['id'];
            $stmt = XOServer::$db->prepare("UPDATE game SET {$paramsPlaceholer} WHERE id = ?");
            $stmt->execute($values);
        }
    }

    public function create()
    {

        $this->params = array_replace(
            array(
                'closed' => 0,
                'filled' => 0,
                'human' => 0,
                'lastping' => time()
            ),
            $this->params
        );

        $this->state = array_fill(0, 9, '');


        $currentSessionSymbol = rand(0, 1) ? 'x' : 'o';
        $this->params[$currentSessionSymbol . '_session'] = session_id();

        $tableKeys = implode(',', array_keys($this->params));
        $placeholder = trim(str_repeat('?,', count($this->params)), ',');

        $stmt = XOServer::$db->prepare("INSERT INTO game ({$tableKeys}) VALUES ($placeholder)");
        $stmt->execute(array_values($this->params));

        if (!($this->params['id'] = XOServer::$db->lastInsertId())) {
            throw new XOGameException(self::ERROR_GAME_CREATE);
        }

        if (!$this->params['human'] && $currentSessionSymbol === 'o') {
            $this->setCompMove(true);
        }
        return $this;

    }

    public function setMove($x, $y)
    {
        $x = intval($x);
        $y = intval($y);
        $type = count(array_filter($this->state)) % 2 == 0 ? 'x' : 'o';


        if (!$type) {
            throw new XOGameException(self::ERROR_INVALID_MOVE);
        }
        if (!in_array($x, range(0, 2)) || !in_array($y, range(0, 2))) {
            throw new XOGameException(self::ERROR_INVALID_MOVE);
        }

        if (!empty($this->state[$x + $y * 3])) {
            throw new XOGameException(self::ERROR_INVALID_MOVE);
        }

        $stmt = XOServer::$db->prepare("INSERT INTO move (game_id, x, y, type) VALUES(?, ?, ?, ?)");
        $stmt->execute(array($this->params['id'], $x, $y, $type));

        $this->state[$x + $y * 3] = $type;

        return $this->checkMove();
    }

    private function checkMove()
    {
        $need = array('1' => 'xxx', '2' => 'ooo');
        $isWin =
            in_array($this->state[0] . $this->state[1] . $this->state[2], $need) ||
            in_array($this->state[3] . $this->state[4] . $this->state[5], $need) ||
            in_array($this->state[6] . $this->state[7] . $this->state[8], $need) ||
            in_array($this->state[0] . $this->state[3] . $this->state[6], $need) ||
            in_array($this->state[1] . $this->state[4] . $this->state[7], $need) ||
            in_array($this->state[2] . $this->state[5] . $this->state[8], $need) ||
            in_array($this->state[0] . $this->state[4] . $this->state[8], $need) ||
            in_array($this->state[2] . $this->state[4] . $this->state[6], $need);

        $isEnd = count(array_filter(
                $this->state,
                function ($e) {
                    return !$e;
                }
            )) == 9;

        return $isWin ? $isWin : ($isEnd ? 0 : -1);

    }

    public function setCompMove($first = false)
    {
        if ($first) {
            $x = rand(0, 2);
            $y = rand(0, 2);
            return $this->setMove($x, $y);
        }

        $posKey = array_rand(
            array_filter(
                $this->state,
                function ($e) {
                    return !$e;
                }
            )
        );

        return $this->setMove($posKey % 3, floor($posKey / 3));
    }

}

class XOGameException extends \Exception
{
    private static $errors = array(

        XOServer::ERROR_UNEXPECTED => 'Неизвестная ошибка',
        XOServer::ERROR_NO_METHOD_EXIST => 'Метод не существует',
        XOServer::ERROR_GAME_NO_FILLED => 'Игра не заполнена',
        XOServer::ERROR_GAME_CLOSED => 'Игра закрыта',
        XOServer::ERROR_GAME_CREATE => 'Ошибка создания игры',
        XOServer::ERROR_GAME_NOT_EXIST => 'Игра не существует',
        XOServer::ERROR_WRONG_MOVE_SYMBOL => 'Неверный символ хода',
        XOServer::ERROR_INVALID_MOVE => 'Неверный ход',
        XOServer::ERROR_PARAM_NOT_PASSED => 'Не передан параметр %s',
    );
    
    public function __construct($code = 0, $var = '')
    {
        if(isset(self::$errors[$code])) {
            $message = self::$errors[$code];
            if($var) {
                $message = sprintf($message, $var);
            }
        } else {
            $message = 'Неизвестная ошибка';
        }

        parent::__construct($message, $code);
    }
}


XOServer::start();
XOServer::sendResponse();



