<?php
/**
 * @package     awa.core
 * @author Ilia Dasevski <il.dashevsky@gmail.com>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace awa\template;

/**
 * Компилятор шаблонов
 */
final class Compiler{

private static $isInit=false;
private static $cacheRemainSource; // кеш оставшегося фрагмента исходника
private static $cacheExprOperation; // кеш для сопоставления текущей операции
private static $source; // исходный код
private static $sourceLen; // длина исходного кода
private static $accumVar='$___accum'; // накапливающая визуализацию шаблона переменная
// строка с возможными экранированными символами. весемь бэкслэшей - это два реальных.
// Строка заканчивается если перед закрывающей ковычкой идёт чётное число бэкслешей
private static $regexpString='("(\\\\\\\\)*")|(((".*?[^\\\\])|")(\\\\\\\\)*")';
// возможные числа в экспоненциальной и шестнадцатеричной формах
private static $regexpNumber='(([0-9]+[eE](\+|-)?[0-9]+)|(0x[0-9A-Fa-f]+)|([0-9]+\.?[0-9]*)|([0-9]*\.?[0-9]+))';
// идентификатор
private static $regexpIdentificator='[a-zA-Z_][a-zA-Z0-9_]*';
// идентификатор всех ключевых слов
private static $regexpKeywords=null;
// ID последней временной переменной
private static $tmpVarId=0;

private static $regexpOperatorOpen;
private static $regexpOperatorClose;

private static $compileFuncMap; // карта пользовательских функций компилятора

// <editor-fold defaultstate="collapsed" desc="=============================== Ключевые слова ==============================">

// ключевые слова
const KEY_REM       ='rem'; // комментарий
const KEY_ENDREM    ='endrem'; // закрытие комментария
const KEY_RAW       ='raw'; // необрабатываемая часть шаблона
const KEY_ENDRAW    ='endraw'; // конец необрабатываемой части шаблона
const KEY_ECHO      ='echo'; // вывод
const KEY_IF        ='if'; // условие
const KEY_ELIF      ='elif'; // альтернативное условие
const KEY_ELSE      ='else'; // действие в противном случае
const KEY_ENDIF     ='endif'; // 
const KEY_FOR       ='for'; // пробежка массива
const KEY_ENDFOR    ='endfor'; // 
const KEY_FORMAT    ='format';
const KEY_INCLUDE   ='include';
const KEY_SET       ='set';
const KEY_INC       ='inc';
const KEY_DEC       ='dec';

// список всех ключевых слов
private static $keywords=array(self::KEY_REM, self::KEY_ENDREM, self::KEY_RAW, self::KEY_ENDRAW,
    self::KEY_IF, self::KEY_ELIF, self::KEY_ELSE, self::KEY_ENDIF,
    self::KEY_FOR, self::KEY_ENDFOR, self::KEY_ECHO, self::KEY_FORMAT, self::KEY_INCLUDE,
    self::KEY_SET, self::KEY_INC, self::KEY_DEC);
// ключевые слова, начинающие операторы
private static $defKeywordsBegin=array(self::KEY_REM,self::KEY_RAW,self::KEY_IF,
    self::KEY_FOR,self::KEY_ECHO,self::KEY_FORMAT,self::KEY_INCLUDE,
    self::KEY_SET,self::KEY_INC,self::KEY_DEC);

private static $keywordsBegin;

// </editor-fold>

// типы добавляемых фрагментов кодов
const CODE_PRINT=1; // печать
const CODE_BEGIN=5; // начало блока
const CODE_END_BEGIN=7; // конец и начало блока одновременно (else, else if)
const CODE_END=8; // конец блока
const CODE_EXPR_OPERATOR=12; // оператор-выражение, оканчивающийся точкой с запятой
const CODE_INNER=15; // вложенный блок кода
const CODE_EXPR=31; // несамостоянельное выражение

const PRINT_BEGIN=1001; // начало печати
const PRINT_INNER=1002; // промежуточная печать
const PRINT_END=1003; // завершение печати
const PRINT_BEGIN_END=1004; // начало и сразу завершение печати

// закрывающие выражения символы
const CLOSE_COMMA=',';
const CLOSE_PARENTHESIS=')';
const CLOSE_BRACKET=']';
const CLOSE_BRACE='}';

// <editor-fold defaultstate="collapsed" desc="================================== Операции =================================">

// типы операций
const OP_UNARY=1; // унарный, стоящий слева от выражения
const OP_BINARY=2;
const OP_SPECIAL=3;
const OP_NOT_EXPR=4;

// типы выражений
const EXPR_STRING=1;
const EXPR_MAP=2;
const EXPR_ARRAY=3;
const EXPR_VARIABLE=4;
const EXPR_OPERATION=5;

private static $operationLevelCount; // количество уровней операций
// приоритеты операций
private static $operations=array(
    array(self::OP_BINARY, array('||')), // логические операторы
    array(self::OP_BINARY, array('&&')),
    array(self::OP_BINARY, array('==','!=')), // операторы сравнения
    array(self::OP_BINARY, array('<','<=','>','>=')), // 
    array(self::OP_BINARY, array('+','-')), // арифметические операторы
    array(self::OP_BINARY, array('*','/','%')), // 
    array(self::OP_UNARY, array('!','-','+','@')), // унарные 
    array(self::OP_SPECIAL, array('[','.','(')), // разыменование массива и обращение к объекту, вызов метода
    array(self::OP_NOT_EXPR, array(':')), // не относятся к выражениям, но могут быть с ними перепутаны
);
private static $unaryOperandLevel=6; // уровень операторов, которые могут идти после унарных операторов

// </editor-fold>

// общие флаги, не пересекаются с частными из-за разрядности
const REQUIRED=0x0100; // если строка не принята, то возбуждается критическая ошибка
const SKIP_SPACES=0x0200; // предварительно пропустить пробельные символы
const CHECK_ONLY=0x0400; // только проверить, принимается ли лексема, без компиляции кода и изменения позиции
//const REQUIRED=0x0200;
//const REQUIRED=0x0400;
// специальные флаги грамматик
const KWD_EXCL_CONTENT=0x0001; // предварительно извлечь контент

// <editor-fold defaultstate="collapsed" desc="====================================== Запуск ===============================">

/**
 * Компиляция шаблона. Параметры компиляции:
 * <table border="1">
 * <tr><th>Ключ</th><th>Описание</th><th>По умолчанию</th></tr>
 * <tr><td>operator_open</td><td>Лексема открытия оператора</td><td>{</td></tr>
 * <tr><td>operator_close</td><td>Лексема закрытия оператора</td><td>}</td></tr>
 * <tr><td>user_func</td><td>Список пользовательских функций вывода</td><td></td></tr>
 * <tr><td>compile_func</td><td>Карта пользовательских функций компилятора.
 * Ключи - сами функции. Приоритетнее пользовательских функций вывода</td><td></td></tr>
 * </table>
 * @param string $source исходный код
 * @param array $options параметры компиляции
 * @return string скомпилированный код
 */
public static function compile($source, array $options){
    if(!self::$isInit){
        self::$isInit=true;
        self::init();
    }
    // конфигурация
    self::$regexpOperatorOpen=isset($options['operator_open'])
            ?preg_quote($options['operator_open'], '/'):'\\{';
    self::$regexpOperatorClose=isset($options['operator_close'])
            ?preg_quote($options['operator_close'], '/'):"\\}\r?\n?";
    
    self::$compileFuncMap=array(); // сбрасываем функции компилятора
    
    // пользовательские функции
    if(isset($options['user_func'])){
        foreach($options['user_func'] as $func){
            self::$compileFuncMap[$func]=function(array &$retCode, array $args)use($func){
                // вызываем пользовательскую функцию и печатаем возвращаемое значение
                Compiler::code($retCode, '$this->userFunctions[\''.$func.'\']('.implode(', ', $args).')', Compiler::CODE_PRINT);
            };
        }
    }
    // пользовательские блочные функции
    if(isset($options['user_block_func'])){
        
    }
    // пользовательские функции компилятора
    if(isset($options['compile_func'])){
        foreach($options['compile_func'] as $func=>$handler){
            self::$compileFuncMap[$func]=$handler;
        }
    }
    $funcList=array_keys(self::$compileFuncMap);
    // ключевые слова, начинающие оператор или вызов функции
    self::$keywordsBegin=array_merge(self::$defKeywordsBegin, $funcList);
    // 
    self::$regexpKeywords='(?:'.implode('|', array_merge(self::$keywords, $funcList)).')(?!\w)';
    // --------------
    self::$source=$source;
    self::$sourceLen=mb_strlen($source);
    // защитная преамбула от прямого доступа
    $ret="<?php if(!defined('AWA_TEMPLATE_GUARD')){exit('Template guard restriction');}\n"; 
    $ret.=self::$accumVar."='';\n"; // сбрасываем накапливающую переменную
    $code=array(); // типизированные фрагменты кода
    $pos=0; // начальная позиция    
    self::grOperatorSequence($pos, $code);
    self::grContent($pos, $code);
    if($pos!==self::$sourceLen){
        self::error('Ожидался конец шаблона', $pos);
    }
    $ret.=self::assembleCode($code);
//    print_r($code);
    $ret.='return '.self::$accumVar.";\n";
    return $ret;
}
// предварительная инициализация компилятора
private static function init(){
    $opArr=array(); // ключи - сами операторы, значения - их длина, для сортировки
    foreach(self::$operations as &$propsLink){
        // превращаем список в множество для быстрой проверки на существование
        $propsLink[1]=self::makeSet($propsLink[1]);
        foreach($propsLink[1] as $operator){
            $opArr[$operator]=mb_strlen($operator);
        }
    }
    self::$operationLevelCount=count(self::$operations);
    arsort($opArr); // сортируем и сохраняем связки и выбираем правильно отсортированные ключи
    $escOper=array(); // список экранированных операторов
    foreach(array_keys($opArr) as $key){
        $escOper[]=preg_quote($key, '/'); // экранируем все спецсимволы
    }
    self::$cacheExprOperation=array(
        implode('|',$escOper), // 0 - регулярное выражение для поиска оператора, начиная с самого длинного
        null,                  // 1 - сохранённая позиция последней проверки
        null,                  // 2 - последний найденный оператор
        null                   // 3 - новая позиция, после найденного оператора
    );
}

// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="================================= Грамматики ================================">

/*
 * Общий вид грамматик:
 * boolean grName(int &$retPos, string &$retCode, int $flags=0, дополнительные параметры)
 * Успешность применения. Передаваемая позиция изменяется на слудующую после распознанного кода только если выполнение успешное.
// 
private static function grName(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    
    // тело
    
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
 */ 

// <editor-fold defaultstate="collapsed" desc="=================================== ----- ===================================">

/**
 * Поиск ключевого слова и попутное генерирование промежуточного
 * @param int $retPos
 * @param string $retCode
 * @param int $flags поддерживаются флаги:
 * REQUIRED ключевое слово должно быть обязательно
 * SKIP_SPACES перед извлечением слова пропустить пробелы
 * KWD_EXCL_CONTENT
 * @param string $retKeyword найденное ключевое слово
 * @param array $allowKeywords список разрешённых ключевых слов. По умолчанию - все
 * @return string ключевое словов или null, если не найдено
 */
private static function grKeyword(&$retPos, array &$retCode, $flags=0, &$retKeyword=null, array $allowKeywords=null){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    if($flags&self::KWD_EXCL_CONTENT){ // если предварительно извлекаем контент
        self::grContent($newPos, $newCode);
    }else if($flags&self::SKIP_SPACES){
        self::grSkipSpaces($newPos, $newCode);
    }
    $keyword='';
    // TODO в начале не должно стоять экранирующего слэша, утверждение о предшествующем тексте: (?<!\\\\)
    // после ключевого слова должна идти либо закрывающая скобка либо любая небуква
    if(self::finiteStateMachine($newPos, self::$regexpOperatorOpen.self::$regexpKeywords, $keyword)){
        $keyword=preg_replace('/^'.self::$regexpOperatorOpen.'/','', $keyword);
        if($allowKeywords===null || in_array($keyword, $allowKeywords)){
            $ret=true;
            if($retKeyword!==null){
                $retKeyword=$keyword;
            }
        }
    }
    if($ret){
        if(!($flags&self::CHECK_ONLY)){
            $retPos=$newPos;
            $retCode=$newCode;
        }
    }else if($flags&self::REQUIRED){
        if($allowKeywords===null){
            self::error('Ожидалось одно из ключевых слов: '.implode(', ', $allowKeywords), $newPos);
        }else{
            self::error('Ожидалось ключевое слово', $newPos);
        }
    }
    return $ret;
}
// пропуск пробельных символов
private static function grSkipSpaces(&$retPos, array &$retCode, $flags=0){
    self::finiteStateMachine($retPos, '\s*');
    return true;
}
// извлечение контента до первого ключевого слова
private static function grContent(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    // пока не дошли до конца и не встретили ключевое слово
    while(self::$sourceLen>$newPos && !self::grKeyword($newPos, $newCode, self::CHECK_ONLY)){
        $newPos++;
    }
    // если позиция изменилась, сохраняем промежуточный код, экранируя кавычки
    if(($ret=$retPos!==$newPos)){ 
        self::code($newCode, '\''.str_replace('\'', '\\\'',
                    mb_substr(self::$source, $retPos, $newPos-$retPos))."'", self::CODE_PRINT);
    }
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}

/**
 * Закрытие оператора. ВАЖНО! Пробелы всегда пропускаются
 * @param int $retPos
 * @param type $retCode
 * @param int $flags доступные флаги:
 * CHECK_ONLY
 * REQUIRED
 * @return boolean
 */
private static function grKeywordClose(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    self::grSkipSpaces($newPos, $newCode);
    
    $ret=self::finiteStateMachine($newPos, self::$regexpOperatorClose);
    if($ret){
        if(!($flags&self::CHECK_ONLY)){
            $retPos=$newPos;
            $retCode=$newCode;
        }
    }else if($flags&self::REQUIRED){
        self::error('Ожидалось закрытие оператора', $newPos);
    }
    return $ret;
}
/**
 * Закрытие скобки выражения
 * @param int $retPos
 * @param type $retCode
 * @param int $flags доступные флаги:
 * @param string $closeLex константа CLOSE_*. По умолчанию - CLOSE_BRACE
 * @return boolean
 */
private static function grClose(&$retPos, array &$retCode, $flags=0, $closeLex=false){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    if($flags&self::SKIP_SPACES){
        self::grSkipSpaces($newPos, $newCode);
    }
    if($closeLex===false){
        $closeLex=self::CLOSE_BRACE;
    }
    if(($ret=self::$source[$newPos]===$closeLex)){
        $newPos++;
    }
    if($ret){
        if(!($flags&self::CHECK_ONLY)){
            $retPos=$newPos;
            $retCode=$newCode;
        }
    }else if($flags&self::REQUIRED){
        self::error('Ожидалось лексема "'.$closeLex.'"', $newPos);
    }
    return $ret;
}


// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="================================== Операторы ================================">


// оператор, может быть пустым
private static function grOperator(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos;
    $newCode=$retCode;
    $ret=self::grContent($newPos, $newCode); // извлекаем конент, так как ключевого слова можем вообще не встретить;    
    $keyword='';
    if(self::grKeyword($newPos, $newCode, 0, $keyword, self::$keywordsBegin)){
        switch($keyword){
            case self::KEY_REM: $ret=self::grRemark($newPos, $newCode); break;
            case self::KEY_RAW: $ret=self::grRaw($newPos, $newCode); break;
            case self::KEY_ECHO: $ret=self::grEcho($newPos, $newCode); break;
            case self::KEY_IF: $ret=self::grIf($newPos, $newCode); break;
            case self::KEY_FOR: $ret=self::grFor($newPos, $newCode); break;
            default: // иначе это функция компилятора
                $args=array();
                do{
                    $exprCode=array();
                    if(($forward=self::grExpression($newPos, $exprCode))){
                        $args[]=self::assembleCode($exprCode);
                    }
                }while($forward);
                $ret=self::grKeywordClose($newPos, $newCode, self::REQUIRED);
                if(isset(self::$compileFuncMap[$keyword])){ // если это простая пользовательская функция
                    $handler=self::$compileFuncMap[$keyword];
                    $handler($newCode, $args);
                }
                break;
        }
    }    
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
// комментарий
private static function grEcho(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $exprCode=array();
    // выражение и закрытие скобки
    $ret=self::grExpression($newPos, $exprCode, self::REQUIRED)
            && self::grKeywordClose($newPos, $exprCode, self::REQUIRED)
            && self::code($newCode, '('.self::assembleCode($exprCode).')', self::CODE_PRINT);
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
// необрабатываемый фрагмент
private static function grRaw(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    // сразу закрываем скобку
    if(self::grKeywordClose($newPos, $newCode, self::REQUIRED)){
        $rawCode='';
        $close=self::$regexpOperatorOpen.self::KEY_ENDRAW.self::$regexpOperatorClose;
        // ищем близжайщую закрывающую комментарий скобку
        if(self::finiteStateMachine($newPos, '.*?'.$close, $rawCode)){
            // удаляем закрывающий оператор и экранируем код
            $string=addcslashes(preg_replace('/'.$close.'$/', '', $rawCode), '\'');
            $ret=self::code($newCode, '\''.$string.'\'', self::CODE_PRINT);
        }else{
            self::error('Ожидалось закрытие необрабатываемого фрагмента', $newPos);
        }
    }
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
// комментарий
private static function grRemark(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    // сразу закрываем скобку
    if(self::grKeywordClose($newPos, $newCode, self::REQUIRED)){
        // ищем близжайщую закрывающую комментарий скобку
        if(self::finiteStateMachine($newPos,
                    '.*?'.self::$regexpOperatorOpen.self::KEY_ENDREM.self::$regexpOperatorClose)){
            $ret=true;
        }else{
            self::error('Ожидалось закрытие комментария', $newPos);
        }
    }
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
// пробежка массива
private static function grFor(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $arrExprCode=array(); // перебираемый массив
    $keyIdCode=array(); // переменная-ключ
    $valueIdCode=array(); // переменная-значение
    // перебираемый массив и один идентификатор обязательны
    $ret=self::grExpression($newPos, $arrExprCode, self::REQUIRED)
            && self::grIdentifacator($newPos, $keyIdCode, self::REQUIRED|self::SKIP_SPACES);
    $arrExpr=self::assembleCode($arrExprCode);
    $keyId=self::assembleCode($keyIdCode);
    // если указана только одна перменная, то это значение
    if(self::grIdentifacator($newPos, $valueIdCode, self::SKIP_SPACES)){
        $valueId=self::assembleCode($valueIdCode);
    }else{
        $valueId=$keyId;
        $keyId=null;
    }
    self::grKeywordClose($newPos, $newCode, self::REQUIRED);
    $cycleCode=array();
    $elseCode=array();
    $keyword=array();
    self::grOperatorSequence($newPos, $cycleCode); // необязательная последовательность операторов внутри
    // если есть альтернативный блок
    if(($isElse=self::grKeyword($newPos, $cycleCode,
                self::KWD_EXCL_CONTENT, $keyword, array(self::KEY_ELSE)))){
        self::grKeywordClose($newPos, $newCode, self::REQUIRED);
        self::grOperatorSequence($newPos, $elseCode);
    }
    // завершаем цикл
    self::grKeyword($newPos, $newCode, self::REQUIRED|self::KWD_EXCL_CONTENT,
                            $keyword, array(self::KEY_ENDFOR))
            && self::grKeywordClose($newPos, $newCode, self::REQUIRED);
    if($isElse){
        // для оптимизации один раз обращаемся к варажению массива, т. к. это может быть вызов метода
        $tmpVar='$'.self::tmpVar();
        self::code($newCode, $tmpVar.'='.$arrExpr, self::CODE_EXPR_OPERATOR);
        $arrExpr=$tmpVar;
        self::code($newCode, 'if('.$arrExpr.'){', self::CODE_BEGIN);
    }
    self::code($newCode, 'foreach('.$arrExpr.' as '
            .($keyId===null?'$'.$valueId.'':'$'.$keyId.'=>$'.$valueId.'').'){', self::CODE_BEGIN);
    self::code($newCode, $cycleCode, self::CODE_INNER);
    self::code($newCode, '}', self::CODE_END);
    if($isElse){
        self::code($newCode, '}else{', self::CODE_END_BEGIN);
        self::code($newCode, $elseCode, self::CODE_INNER);
        self::code($newCode, '}', self::CODE_END);
    }
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
// условие
private static function grIf(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $exprCode=array();
    // первое выражение и закрытие скобки
    $ret=self::grExpression($newPos, $exprCode, self::REQUIRED)
            && self::grKeywordClose($newPos, $exprCode, self::REQUIRED)
            && self::code($newCode, 'if('.self::assembleCode($exprCode).'){', self::CODE_BEGIN);
    do{
        self::grOperatorSequence($newPos, $newCode); // необязательная последовательность операторов
        $keyword='';
        self::grKeyword($newPos, $newCode, self::REQUIRED|self::KWD_EXCL_CONTENT,
                $keyword, array(self::KEY_ENDIF,self::KEY_ELIF,self::KEY_ELSE));
        $forward=false;
        switch($keyword){
            case self::KEY_ELIF:
                // получаем выражение и продолжем анализ ключевых слов
                $exprCode=array();
                self::grExpression($newPos, $exprCode, self::REQUIRED)
                && self::grKeywordClose($newPos, $exprCode, self::REQUIRED)
                && self::code($newCode, '}else if('.self::assembleCode($exprCode).'){', self::CODE_END_BEGIN);
                $forward=true;
                break;
            case self::KEY_ELSE: // альтернативная ветка по умолчанию
                $notUsedKeyword='';
                // получаем последний блок кода и закрываем блок if
                self::grKeywordClose($newPos, $newCode, self::REQUIRED)
                    && self::code($newCode, '}else{', self::CODE_END_BEGIN);
                self::grOperatorSequence($newPos, $newCode); // необязательная последовательность операторов
                self::code($newCode, '}', self::CODE_END)
                    && self::grKeyword($newPos, $newCode, self::REQUIRED|self::KWD_EXCL_CONTENT,
                            $notUsedKeyword, array(self::KEY_ENDIF))
                    && self::grKeywordClose($newPos, $newCode, self::REQUIRED);
                break;
            case self::KEY_ENDIF: // конец блока. сразу закрываем скобку и завершаем цикл
                self::grKeywordClose($newPos, $newCode, self::REQUIRED)
                    && self::code($newCode, '}', self::CODE_END);
                break;
        }
        
        
    }while($forward);
    
    
    
    
    if($ret && !($flags&self::CHECK_ONLY)){
        $retPos=$newPos;
        $retCode=$newCode;
    }
    return $ret;
}
// последовательность операторов
private static function grOperatorSequence(&$retPos, array &$retCode, $flags=0){
    $flags=0; // заглушка
    while(self::grOperator($retPos, $retCode)){ } // считаем количество операторов
    return true;
}

// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="================================ Выражения ==================================">

// идентификатор
private static function grIdentifacator(&$retPos, array &$retCode, $flags=0){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    if($flags&self::SKIP_SPACES){
        self::grSkipSpaces($newPos, $newCode);
    }
    $id='';
    $ret=self::finiteStateMachine($newPos, self::$regexpIdentificator, $id)
            &&self::code($newCode, $id, self::CODE_EXPR);
    if($ret){
        if(!($flags&self::CHECK_ONLY)){
            $retPos=$newPos;
            $retCode=$newCode;
        }
    }else if($flags&self::REQUIRED){
        self::error('Ожидался идентификатор', $newPos);
    }
    return $ret;
}
/**
 * константа, находится всегда внутри операторов
 * @param int $retPos
 * @param type $retCode
 * @param int $flags доступные флаги:
 * CHECK_ONLY, REQUIRED
 * @return boolean
 */
private static function grConstant(&$retPos, array &$retCode, $flags=0, &$retExprType=null){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    $char=self::$source[$newPos]; // для оптимизации анализируем первый символ
    if($char==='"'){ // строковый литерал
        $string='';
        if(self::finiteStateMachine($newPos, self::$regexpString, $string)){
            // заменяем двойные кавычки на одинарные,
            // убираем лишнее экранирование двойных ковычек и добавляем экранирование одинарных и бэкслеша
            $string='\''.addcslashes(stripcslashes(substr($string,1,-1)), '\'\\').'\'';
//            $string='\''.str_replace('\'', '\\\'', substr($string,1,-1)).'\'';
            $ret=self::code($newCode, $string, self::CODE_EXPR);
        }else{
            self::error('Ожидось окончание строкового литерала', $newPos);
        }
    }else if($char==='{'){ // карта
        exit('Карты в разработке');
    }else if($char==='['){ // массив
        exit('Массивы в разработке');
    }else{
        $result='';
        if(self::finiteStateMachine($newPos, self::$regexpNumber, $result)){ // число
            $ret=self::code($newCode, $result, self::CODE_EXPR);
        }
    }
    if($ret){
        if(!($flags&self::CHECK_ONLY)){
            $retPos=$newPos;
            $retCode=$newCode;
        }
    }else if($flags&self::REQUIRED){
        self::error('Ожидалось константа', $newPos);
    }
    return $ret;
}
/**
 * выражение, находится всегда внутри операторов
 * @param int $retPos
 * @param type $retCode
 * @param int $flags доступные флаги:
 * CHECK_ONLY, REQUIRED
 * @param int $level приоритет операции. По умолчанию начинаем с нижнего уровня операторов
 * @param type $retExprType
 * @return boolean
 */
private static function grExpression(&$retPos, array &$retCode, $flags=0, $level=false, &$retExprType=null){
    $newPos=$retPos; // не затираем переданную позицию
    $newCode=$retCode;
    $ret=false;
    self::grSkipSpaces($newPos, $newCode); // пропускаем пробелы, в выражении они не участвуют
    $char=self::$source[$newPos];
    // проверяем на конец выражения
    if($char===','||$char==='}'||$char===')'||$char===']'||self::grKeywordClose($newPos, $newCode, self::CHECK_ONLY)){ 
    }else{
        if($level===false){
            $level=0; // уровень по умолчанию - нижний
        }
        if($level===self::$operationLevelCount){ // если достигли предела уровня операторов
            if($char==='('){ // если открывающая скобка, то внутри неё должно быть другое выражение
                $resExprCode=array();
                $ret=self::grExpression($newPos, $resExprCode, self::REQUIRED, false, $retExprType)
                        && self::code($newCode, '('.self::assembleCode($resExprCode).')', self::CODE_EXPR);
            }else if(($ret=self::grConstant($newPos, $newCode, 0, $retExprType))){ // пытаемся получить константу
            }else{
                $idCode=array();
                $varBeginPos=$newPos;
                if(self::grIdentifacator($newPos, $idCode)){ // если получили идентификатор, то это переменная
                    $varName=self::assembleCode($idCode);
                    if($varName==='this'){
                        self::error('Нельзя обращаться к переменной this', $varBeginPos);
                    }
                    $ret=self::code($newCode, '$'.$varName, self::CODE_EXPR);
                }
            } 
        }else{
            $nextLevel=$level+1;
            $op=''; // найденный оператор
            switch(self::$operations[$level][0]){ // определяем тип оператора
                case self::OP_BINARY:
                    $resExprCode=array();
                    // если получена левая часть выражения более высокого уровня
                    if(self::grExpression($newPos, $resExprCode, 0, $nextLevel, $retExprType)){
                        $resExpr=self::assembleCode($resExprCode);
                        do{ // если удалось распознать оператор нужного уровня
                            // могут ли дальше быть операнды того же уровня
                            if(($canHasNextSibling=self::checkExprOperation($newPos, $level, $op))){
                                $rightExprCode=array(); // обязательно должна идти правая часть более выского уровня
                                if(self::grExpression($newPos, $rightExprCode, self::REQUIRED, $nextLevel, $retExprType)){
                                    $resExpr='('.$resExpr.')'.$op.'('.self::assembleCode($rightExprCode).')';
                                }
                            }
                        }while($canHasNextSibling);
                        $ret=self::code($newCode, $resExpr, self::CODE_EXPR); // пишем результат
                    }
                    break;
                case self::OP_UNARY:
                    // если обнанужили унарный оператор, то после него могут идти другие унарные операторы
                    if(self::checkExprOperation($newPos, $level, $op)){ // 
                        $resExprCode=array();
                        $ret=self::grExpression($newPos, $resExprCode,
                                self::REQUIRED, self::$unaryOperandLevel, $retExprType)
                            && self::code($newCode, $op.self::assembleCode($resExprCode), self::CODE_EXPR);
                    }else{ // иначе - унарный оператор не найден, переходим на следующий уровень
                        $ret=self::grExpression($newPos, $newCode, 0, $nextLevel, $retExprType);
                    }
                    break;
                case self::OP_SPECIAL:
                    $resExprCode=array();
                    // если получена левая часть выражения более высокого уровня
                    if(self::grExpression($newPos, $resExprCode, 0, $nextLevel, $retExprType)){
                        $resExpr=self::assembleCode($resExprCode);
                        do{ // анализируем специальный оператор, если такой обнаружен
                            if(($forward=self::checkExprOperation($newPos, $level, $op))){
                                switch($op){
                                    case '[': // разыменовывание массива
                                        $keyExprCode=array();
                                        // внутри скобок обязательно должен быть ключ
                                        self::grExpression($newPos, $keyExprCode, self::REQUIRED)
                                                && self::grClose($newPos, $keyExprCode,
                                                        self::REQUIRED|self::SKIP_SPACES, self::CLOSE_BRACKET);
                                        $resExpr.='['.self::assembleCode($keyExprCode).']';
                                        break;
                                    case '(': // вызов метода
                                        $paramArr=array();
                                        $unusedCode=array();
                                        do{ // получаем выражение-параметр и смотрим, идёт ли за ним следующий параметр через запятую
                                            $paramCode=array();
                                            if(self::grExpression($newPos, $paramCode)){
                                                $hasNextParam=self::grClose($newPos, $paramCode,
                                                        self::SKIP_SPACES, self::CLOSE_COMMA);
                                                $paramArr[]=self::assembleCode($paramCode);
                                            }else{
                                                $hasNextParam=false;
                                            }
                                        }while($hasNextParam);
                                        // после вызова закрываем скобку
                                        $ret=self::grClose($newPos, $unusedCode,
                                                self::REQUIRED|self::SKIP_SPACES, self::CLOSE_PARENTHESIS);
                                        $resExpr.='('.implode(',', $paramArr).')';
                                        break;
                                    case '.': // обращение к свойству объекта возможно только через идентификатор
                                        $idCode=array();
                                        self::grIdentifacator($newPos, $idCode, self::REQUIRED);
                                        $resExpr.='->'.self::assembleCode($idCode);
                                        break;
                                }
                            }
                        }while($forward);
                        $ret=self::code($newCode, $resExpr, self::CODE_EXPR); // пишем результат
                    }
                    break;  
                case self::OP_NOT_EXPR: // пропускаем ступень
                    $ret=self::grExpression($newPos, $newCode, 0, $nextLevel, $retExprType);
                    break;
            }
        } 
    }
    if($ret){
        if(!($flags&self::CHECK_ONLY)){
            $retPos=$newPos;
            $retCode=$newCode;
        }
    }else if($flags&self::REQUIRED){
        self::error('Ожидалось выражение', $newPos);
    }
    return $ret;
}

// </editor-fold>

// </editor-fold>

// <editor-fold defaultstate="collapsed" desc="==================================== Утилиты ================================">

/**
 * Сборка фрагмента кода
 * @param array $code типизированный фрагмент
 * @param int $spacer размер отступа
 * @return string готовый фрагмент кода
 */
private static function assembleCode(array $code, $spacer=0){
    $ret='';
    $isPrintBegin=false;
    foreach($code as $i=>$fragProps){
        if($fragProps[1]===self::CODE_PRINT){ // если печать, то её необходимо оптимизировать
            $hasNextPrint=isset($code[$i+1]) && $code[$i+1][1]===self::CODE_PRINT; // сдедует ли дальше тоже печать
            if(!$isPrintBegin && $hasNextPrint){
                $isPrintBegin=true;
                $fragProps[1]=self::PRINT_BEGIN;
            }else if(!$isPrintBegin && !$hasNextPrint){
                $fragProps[1]=self::PRINT_BEGIN_END; // печать закончалась, поэтому не ставим флаг начала печати
            }else if($isPrintBegin && $hasNextPrint){
                $fragProps[1]=self::PRINT_INNER;
            }else if($isPrintBegin && !$hasNextPrint){
                $fragProps[1]=self::PRINT_END;
                $isPrintBegin=false; // сбрасываем флаг начала печати, так как она закончилась
            }
        }
        $ret.=self::assembleCodeRec($fragProps, $spacer);
    }
    
//    print_r($code);echo('==='.$ret)."\n\n\n\n";
    
    return $ret;
}
// 
private static function assembleCodeRec(array $fragProps, &$spacer){
    $frag=$fragProps[0]; // фрагмент
    switch($fragProps[1]){ // определяем тип кода
        case self::PRINT_BEGIN: $ret=self::makeSpacer($spacer).self::$accumVar.'.='.$frag; break;
        case self::PRINT_INNER: $ret='.'.$frag; break;
        case self::PRINT_END: $ret='.'.$frag.";\n"; break;
        case self::PRINT_BEGIN_END: $ret=self::makeSpacer($spacer).self::$accumVar.'.='.$frag.";\n"; break;
        case self::CODE_BEGIN: $ret=self::makeSpacer($spacer++).$frag."\n"; break;
        case self::CODE_END_BEGIN: $ret=self::makeSpacer($spacer-1).$frag."\n"; break;
        case self::CODE_END: $ret=self::makeSpacer(--$spacer).$frag."\n"; break;
        case self::CODE_EXPR_OPERATOR: $ret=self::makeSpacer($spacer).$frag.";\n"; break;
        case self::CODE_INNER: $ret=self::assembleCode($frag, $spacer); break;
        default: $ret=$frag; break;
    }
    return $ret;
}
// делает нужный пробелы
private static function makeSpacer($spacerCount){
    return $spacerCount?str_repeat('  ', $spacerCount):''; // оптимизация
}
/**
 * Проверка, идёт ли дальше заданная операция заданного уровня и какая, если идёт
 * В любом случае пропускает пробелы.
 * В случае совпадения пропускает лексему. Кэширует данные, работает быстро.
 * Не путает операторы +, ++, <, << <<+ и т. п.
 * @param int $retPos - текущее и изменяемое положение в строке
 * @param int $level 
 * @param string $retOperator распознанный оператор
 */
private static function checkExprOperation(&$retPos, $level, &$retOperator){
    $ret=false;
    // сначала составляем регулярное выражение
    $cache=&self::$cacheExprOperation;
    if($cache[1]===$retPos){ // если предыдущая позиция совпадает с текущей, можно выдать кэш
        $op=$cache[2];
        $newPos=$cache[3];
    }else{
        $newPos=$retPos;
        $unusedCode=array();
        self::grSkipSpaces($newPos, $unusedCode); // пропускам пробелы
        $op='';
        if(self::finiteStateMachine($newPos, $cache[0], $op)){ // иначе подбираем текущий
            $cache[1]=$retPos; // сохраняем позицию, оператор и новую позицию после его нахождения
            $cache[2]=$op;
            $cache[3]=$newPos;
        }
    }
    if($op && isset(self::$operations[$level][1][$op])){ // если определили искомый оператор
        $ret=true;
        $retPos=$newPos;
        $retOperator=$op;
    }
    return $ret;
}
/**
 * Добавляет код
 * Типы фрагментов кода:
 * <table border="1">
 * <tr><th>Константа</th><th>Описание</th><th>Пример</th></tr>
 * <tr><td>Compiler::CODE_PRINT</td>
 *      <td>Выражение, выводимое на печать. Точка с запятой в конце не указывается.</td>
 *      <td>'Это строка' или 8+9+4+2</td></tr>
 * <tr><td>Compiler::CODE_BEGIN</td>
 *      <td>Открытие блока. В конце указывается открывающая скобка.</td>
 *      <td>while(true){ или if(1==1){</td></tr>
 * <tr><td>Compiler::CODE_END_BEGIN</td>
 *      <td>Одновременное закрытие и открытие блока. Указывается закрывающая и открывающая скобки.</td>
 *      <td>}else if(false){ или }else{</td></tr>
 * <tr><td>Compiler::CODE_EXPR_OPERATOR</td>
 *      <td>Самостоятельное выражение. В конце точка с запятой не указыватся.</td>
 *      <td>$isNow=isNow() или funcCall()</td></tr>
 * <tr><td>Compiler::CODE_END</td>
 *      <td>Закрытие блока. Указывается закрывающая скобка.</td>
 *      <td>}</td></tr>
 * <tr><td>Compiler::CODE_INNER</td>
 *      <td>Вложенный список фрагментов кода - массив, аналогичный $baseCode</td>
 *      <td></td></tr>
 * </table>
 * @param array $baseCode накопитель кода
 * @param mixed $newCode строка фрагмента или типизированный код
 * @param int $type тип фрагмента кода
 * @return boolean true
 */
public static function code(array &$baseCode, $newCode, $type){
    $baseCode[]=array($newCode, $type);
    return true;
}
/**
 * конечный автомат, пробегает строку до тупика по заданному регулярному выражению и возвращает первую подходящую строку
 * @param int $retPos - текущее и изменяемое положение в строке
 * @param string $regexp регулярное выражение PCRE, которое будет обёрнуто в /^REGEXP/us
 * @param string $acceptedString принятая строка
 * @return int успешность применения
 */
private static function finiteStateMachine(&$retPos, $regexp, &$acceptedString=null){
    $matches=array();
    // в регулярном выражении считаем переносы строк как обычный символ
    // ищем вхождение начиная с текущей позиции в коде. Используем символ ^, поэтому не применяем параметр $offset
    $remainSource=self::remainSource($retPos);
    $ret=(bool)preg_match('/^('.$regexp.')/us', $remainSource, $matches);
    if($ret){
        $accepted=$matches[0];
        $retPos+=mb_strlen($accepted); // смещаем позицию на принятое автоматом количество символов
        if($acceptedString!==null){ // если необходимо вернуть найденное совпадение
            $acceptedString=$accepted;
        }
    }
    return $ret;
}
/**
 * @return string идентификатор уникальной временной переменной
 */
public static function tmpVar(){
    return '___tplUniqVar'.(++self::$tmpVarId);
}
// оставшаяся часть исходника. Кэширует повторяющиеся операции взятия подстроки
private static function remainSource($pos){
    if(!self::$cacheRemainSource || self::$cacheRemainSource[0]!==$pos){ // если кэш устарел
        self::$cacheRemainSource=array($pos, mb_substr(self::$source, $pos));
    }
    return self::$cacheRemainSource[1];
}
/**
 * Сообщение об ошибке
 * @param string $msg сообщение
 * @param int $pos позиция. Если не указывать, то будет выведено только сообщение
 */
private static function error($msg, $pos=null){
    if($pos!==null){
        $line=substr_count(self::$source, "\n", 0, $pos)+1;
        $rightBreakPos=mb_strpos(self::$source, "\n", $pos);
        if($rightBreakPos===false){ // предельная граница файла
            $rightBreakPos=self::$sourceLen;
        }
        $leftBreakPos=mb_strrpos(self::$source, "\n", $rightBreakPos-self::$sourceLen-1);
        if($leftBreakPos===false){ // первый символ файла
            $leftBreakPos=0;
        }
        $sourceLine=mb_substr(self::$source, $leftBreakPos, $rightBreakPos-$leftBreakPos);
        $arrow=str_repeat(' ', $pos-1-$leftBreakPos).'^'; // указатель
        // подсвечиваем снизу символ ошибки. Учитываем, что символ может быть в начале
        $msg='Error on line '.$line.': '.$msg.':'."\n".trim($sourceLine,"\n")."\n".$arrow."\n";
    }
    throw new TemplateException($msg);
}

/**
 * Создаёт из списка множество
 * @param array $arr [значение1, значение2]
 * @param boolean $fromKeys Делать множество из ключей
 * @return array [значение1=>значение1, значение2=>значение2]
 */
public static function makeSet(array $arr, $fromKeys=false){
    $values=$fromKeys?array_keys($arr):array_values($arr);
    return array_combine($values, $values);
}

// </editor-fold>
    
}
