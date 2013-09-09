<?php
/**
 * @package     awa.core
 * @author Ilia Dasevski <il.dashevsky@gmail.com>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace awa\template;

use Closure;

/**
 * Шаблонизатор AWA Template v2.0
 * @link http://awaproject.org/wiki/AWA_Template официальная документация
 */
class Template{
    
private static $isInit=false;
    
private $cacheCompiledFiles=array(); // шаблона=>откомпилированный файл

private $baseOptions=array(); // базовые оцпии шаблонов
private $options; // опции конкретного шаблона, хранятся до ленивого объединения с базовыми опциями
private $currentOptions=null; // параметры шаблона для передачи в обработчики

private $userFunctions=array(); // пользовательские функции

// Блочные пользовательские функции
// Вызываются один раз. Из шаблона могут вызвать метод innerContent - лениво вычисляющееся содержимое.
// Метод может вызываться сколько угодно раз, таким образом можно делать циклы.

// Функции компилятора
// Получают список исходных кодов параметров, возвращают PHP код


/**
 * Определение времеми модификации или создания исходного кода шаблона.
 * Для изменения способа получения времени, метод наследуется.
 * @param string $name имя шаблона
 * @return int время модификации в UNIX-формате
 * @throws TemplateException исключение в том случае, если шаблон не найден
 */
protected function getSourceTime($name){
    $sourceDir=&$this->cacheStdSourceDir;
    if($sourceDir===null){
        $sourceDir=defined('AWATPL_SOURCE_DIR')?AWATPL_SOURCE_DIR:'templates';
    }
    $fileName=$sourceDir.'/'.$name;
    if(!file_exists($fileName)){
        throw new TemplateException('Шаблон "'.$fileName.'" не найден');
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
    $compiledDir=$this->cacheStdCompiledDir;
    if(!file_exists($compiledDir)){
        mkdir($compiledDir, 0755, true);
    }
    // шаблон уже прошёл проверку на существовании в методе получения времени модификации
    return file_get_contents($this->cacheStdSourceDir.'/'.$name);
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
    $compiledDir=&$this->cacheStdCompiledDir;
    if($compiledDir===null){
        $compiledDir=defined('AWATPL_COMPILED_DIR')?AWATPL_COMPILED_DIR:'cache/templates';
    }
    // имя скомпилированного файла. Добавляем суффикс .tmp, чтоб файл считался временным и мог удаляться сборщиком
    return $compiledDir.'/'.strtr($name, '/', '.').'.tmp.php';
}
/**
 * Регистрация пользовательской функции вывода. Функция будет во всех шаблонах текущего менеджера.
 * @param string $name имя функции. Состоит из латинских букв, цифр и знака подчёркивания.
 * Первый символ должен быть буквой или знаком подчёркивания
 * @param Closure $func обработчик получает список параметров
 * в той последовательности, в какой они указаны в шаблоне. Возвращаемое значение выводится в результат
 * @return Template this
 */
public function registerFunction($name, Closure $func){
    $this->userFunctions[$name]=$func;
    return $this;
}
/**
 * Регистрация пользовательской функции для компилятора. Имеет приоритет над пользовательскими функциями вывода.
 * Функция будет во всех шаблонах текущего менеджера.
 * API компилятора:<ul>
 * <li>Compiler::tmpVar() - гарантированно уникальное имя временной переменной.</li>
 * </ul>
 * @param string $name имя функции. Состоит из латинских букв, цифр и знака подчёркивания.
 * Первый символ должен быть буквой или знаком подчёркивания
 * @param Closure $func (array &$retCode, array $args)
 *      $retCode - возвращаемый код, в который при помощи метода Compiler::code добавляются фрагменты.
 *      $args - список PHP кодов аргументов, переданных в шаблоне. Например, '"str"' или '45+4/4' или '$func(arg)'
 * @see Compiler::code
 * @return Template this
 */
public function registerCompilerFunction($name, Closure $func){
    $this->userFunctions[$name]=$func;
    return $this;
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
        // время получаем вне прерывания, так как внутри него есть проверка существования исходникак
        $sourceTime=$this->getSourceTime($name);
        // если скомпилированный файл устарел или не существует, перекомпилируем его
        if(!file_exists($compFileName) || filemtime($compFileName)<$sourceTime){
            $code=Compiler::compile($this->getSource($name), array(
                'user_func'=>array_keys($this->userFunctions) // пользовательские функции
            ));
            file_put_contents($compFileName, $code);
        }
    }
    return $this->exec($compFileName, $vars?$vars:array());
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
// базовая инициализация, вызывается лениво один раз
private static function init(){
    define('AWA_TEMPLATE_GUARD', true); // защита от прямого доступа к шаблонам
}

}



