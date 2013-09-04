<?php
/**
 * @package     awa.core
 * @copyright   Copyright (C) 2012 Ilia Dasevski <il.dashevsky@gmail.com>, Inc. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace awa\template;

use Closure;

/**
 * Шаблонизатор AWA Template v2.0
 * 
 * Расширение шаблонов - .html
 * 
 * Синтаксис
 * 
 * Операторы. Могут вызываться самостоятельно. Не возвращают значений и не могут вызываться внутри выражений.
 * <table>
 * <tr><td>{rem}{endrem}</td><td>комментарий</td></tr>
 * <tr><td>{if expression} {elif expression} {else} {endif}</td><td>условный оператор. Ветки elif и else необязательны</td></tr>
 * <tr><td>{for expression key value} {else} {endfor}<br/>{for expression value} {else} {endfor}</td>
 *      <td>Перебор массива. Ключ и значение - идентификаторы. Ключ можно не указывать</td></tr>
 * <tr><td>{echo expression}</td><td>Вставка значения выражения в шаблон</td></tr>
 * <tr><td>{lang expr}</td><td>Вставка языковой конструкции в шаблон. expr это массив вида [module,pack,name] или string. Модуль по умолчанию - текущий, пакет - устанавливается перед рендерингом или main. Если указывать константный массив или строк, используется оптимизация.</td></tr>
 * <tr><td>{format expr expr1 expr2 ... exprN}</td><td>Аналогично lang, но форматирует вывод с помощю sprintf-подобной функции</td></tr>
 * <tr><td>{include name_expr map_expr}</td><td>Вставка шаблона с указанными параметрами-картой. Не проверяется на рекурсию. Имя указывается аналогично имени lang-конструкции, но без имени пакета. Настройки шаблона наследуются.</td></tr>
 * <tr><td>{set lvalue_expr expr}</td>Присваивание.<td></td></tr>
 * <tr><td>{inc lvalue_expr}</td><td>Увеличение на единицу</td></tr>
 * <tr><td>{dec lvalue_expr}</td><td>Уменьшение на единицу</td></tr>
 * </table>
 * <table>
 * 
 * Выражения. Не могут использоваться вне операторов
 * Идентификатор                                := a-zA-Z_0-9<br/>
 * Переменная                                   := идентификатор<br/>
 * Строка                                       := "любые символы"<br/>
 * Карта                                        := {выражение-ключ:выражение-значение, ...}<br/>
 * Массив                                       := [выражение, выражение, ...]<br/>
 * Обращение к элементу массива или карты       := выражение[выражение-ключ]<br/>
 * Обращение к свойству объекта                 := выражение.идентификатор<br/>
 * 
 * Операции, возвращающие значения. Не могут использоваться вне операторов
 * @-at - предварительная проверка на существование переменной
 * 
 * Приоритеты операций
 * (), [], .        - унарные,  лево-ассоциативные
 * -, +, @, !       - унарные,  право-ассоциативные
 * /, *, %          - бинарные, лево-ассоциативные
 * +, -             - бинарные
 * <, <=, >, >=     - бинарные
 * ==, !=           - бинарные
 * &&               - бинарные
 * ||               - бинарные
 */
class Template{
    
private static $isInit=false;
    
private $cacheCompiledFiles=array(); // шаблона=>откомпилированный файл
private $compileOptions=array(); // опции компилятора

private $baseOptions=array(); // базовые оцпии шаблонов
private $options; // опции конкретного шаблона, хранятся до ленивого объединения с базовыми опциями
private $currentOptions=null; // параметры шаблона для передачи в обработчики

// Блочные пользовательские функции
// Вызываются один раз. Из шаблона могут вызвать метод innerContent - лениво вычисляющееся содержимое.
// Метод может вызываться сколько угодно раз, таким образом можно делать циклы.

// Функции компилятора
// Получают список исходных кодов параметров, возвращают PHP код


// <editor-fold defaultstate="collapsed" desc="========================= Кофигурация ======================">

// базовая инициализация, вызывается лениво один раз
private static function init(){
    define('AWA_TEMPLATE_GUARD', true); // защита от прямого доступа к шаблонам
}

/**
 * Определение времеми модификации или создания исходного кода шаблона.
 * Для изменения способа получения времени, метод наследуется.
 * @param string $name имя шаблона
 * @return int время модификации в UNIX-формате
 * @throws TemplateException исключение в том случае, если шаблон не найден
 */
protected function getSourceTime($name){
    $fileName='templates/'.$name.'.html';
    if(!file_exists($fileName)){
        throw new TemplateException('Шаблон '.$fileName.' не найден');
    }
    return filemtime($fileName);
}
/**
 * Получение исходного кода шаблона.
 * Для изменения способа получения исходного кода, метод наследуется.
 * @param string $name имя шаблона
 * @return string исходный код
 */
protected function getSource($name){
    // если затребовали исходный код, значит будет и сохранение скомпилированного шаблона.
    // предварительно создаём директорию под скомпилированный шаблон
    $compiledDir='cache/templates';
    if(!file_exists($compiledDir)){
        mkdir($compiledDir, 0755, true);
    }
    // шаблон уже прошёл проверку на существовании в методе получения времени модификации
    return file_get_contents('templates/'.$name.'.html');
}
/**
 * Получение имени скомпилированного файла шаблона. Файл не обязательно должен существовать.
 * Поддерживается только локальное хранение скомпилированных шаблонов.
 * Опции можно получить через $this->getOptions().
 * Для изменения способа получения имени файла, метод наследуется.
 * @param string $name имя шаблона
 * @return string имя скомпилированного файла
 */
protected function getCompiledFileName($name){
    // имя скомпилированного файла. Добавляем суффикс .tmp, чтоб файл считался временным и мог удаляться сборщиком
    return 'cache/templates/'.strtr($name, '/', '.').'.tmp.php';
}
/**
 * базовые опции шаблонов, которые могут переопределяться для каждого шаблона в отдельности
 * @param array $options 
 * @return Template this
 */
public function setBaseTemplateOptions(array $options){
    $this->baseOptions=$options;
    return $this;
}

// </editor-fold>

/**
 * 
 */
public function __construct(){
    if(!self::$isInit){
        self::$isInit=true;
        self::init();
    }
}
/**
 * Опции последнего шаблона, вызванного методом render.
 * Используется внутри таких методов, как getSource, getCompiledFileName и другими
 * @return array
 */
protected function getOptions(){
    if($this->currentOptions===null){ // используем текущие опции как флаг их неинициализированности
        $this->currentOptions=$this->options;
        // индивидуальные опции не перезаписываются базовыми
        $this->currentOptions+=$this->baseOptions;
    }
    return $this->currentOptions;
}
/**
 * Визуализация шаблона.
 * @param string $name уникальное имя шаблона, однозначно определяющее его исходный код.
 * @param array $vars карта переменных, передаваемых в шаблон
 * @param array $options дополнительные опции шаблона. Могут переопределить базовые опции.
 * @return string результат
 */
public function render($name, array $vars=null, array $options=null){
    $compFileName=&$this->cacheCompiledFiles[$name];
    if($compFileName===null){
        $this->currentOptions=null; // флаг: результирующие опции не инициализированы
        // запоминаем опции для ленивой передачи в управляющие методы
        $this->options=$options?$options:array();
        $compFileName=$this->getCompiledFileName($name); // получаем имя скомпилированного файла
        // если скомпилированный файл устарел или не существует, перекомпилируем его
        if(!file_exists($compFileName) || filemtime($compFileName)<$this->getSourceTime($name)){
            $code=Compiler::compile($this->getSource($name), $this->compileOptions);
            file_put_contents($compFileName, $code);
        }
    }
    return self::exec($compFileName, $vars?$vars:array());
}
/**
 * Сборка шаблона. Используются длинный префикс для предотвращения совпадения имён.
 * @param type $___fileName
 * @param type $___vars
 * @return string
 */
private function exec($___fileName, array $___vars){
    extract($___vars); // превращаем элементы массива в локальные переменные
    return include $___fileName;
}    


}



