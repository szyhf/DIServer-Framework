<?php

namespace \DIServer\Lib\DI\Core\DIContainer;

use \DIServer\Lib\DI\DIContainer\Exception;

/**
 * IOC容器类
 */
class DIContainer
{

    /**
     * 默认容器实例
     * @var DIContainer
     */
    protected static $defaultIOC;

    /**
     * 默认实例（单例）的键
     * @var string 
     */
    protected $defaultKey = '0';

    /**
     * 类名\抽象类名\接口名->实例的映射
     * 抽象类名\接口名映射显然不支持自动根据构造函数实例化（=。=）
     * [$type=>[
     * 	    $key=>$instance
     * 	    ]
     * ]
     * @var array 
     */
    protected $instances = [];

    /**
     * 已注册的类名\抽象类名\接口名
     * @var array 
     */
    protected $registries = [];

    /**
     * 类名\抽象类名\接口名->自定义工厂函数的映射
     * [$type=>[
     * 	    $key=>$factory
     * 	    ]
     * ]
     * @var array 
     */
    protected $factorys = [];

    /**
     * 自定义的工厂函数\构造函数参数
     * [$type=>[
     * 	    $key=>$params
     * 	    ]
     * ]
     * @var type 
     */
    protected $selfParams = [];

    /**
     * 抽象名\接口名\类名->它的某个子孙抽象名\某个子孙接口名\某个子孙类名的映射
     * [$type=>$type]
     * 子子孙孙无穷匮也
     * @var array 
     */
    protected $interfaces = [];

    /**
     * 构筑堆栈（记录当前正在构筑的类的情况）
     * [$type,...,$type]
     * @var array
     */
    protected $buildStack = [];

    /**
     * 已经被创建了实例的类\抽象类\接口
     * [$type=>[$key=>true|false]]
     * @var array 
     */
    protected $implemented = [];

    /**
     * 默认容器实例
     * @return DIContainer
     */
    public static function Container()
    {
//	if (!self::$defaultIOC)
	{
	    self::$defaultIOC = new DIContainer();
	}
	return self::$defaultIOC;
    }
    
    /**
     * 注册一个类型
     * @param string $class 类全名（请勿使用抽象类）
     * @param array $constructorParams （可选）构造方法的自定参数字典
     * @param string $key （可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\ClassNotExistException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\RegistedException
     */
    public function RegisterClass(string $class, array $constructorParams = []
    , string $key = null)
    {
	$class = $this->normalizeType($class);
	if (!class_exists($class))
	{
	    throw new DIContainer\ClassNotExistException($class, $key);
	}
	elseif ($this->IsRegistered($class, $key))
	{
	    throw new DIContainer\RegistedException($class, $key);
	}
	else
	{
	    $key = $this->normalizeKey($key);
	    $this->registries[$class][$key] = TRUE;
	    $this->registerSelfParams($class, $constructorParams, $key);
	}
    }

    /**
     * 用工厂方法注册一个类型
     * @param string $class 类型全称
     * @param \Closure $factory 工厂方法（返回值为实例化结果）
     * @param array $factoryParams （可选）工厂方法的自定参数字典
     * @param string $key （可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\ClassNotExistException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\RegistedException
     */
    public function RegisterClassByFactory(string $class, \Closure $factory
    , array $factoryParams = [], string $key = null)
    {
	$key = $this->normalizeKey($key);
	$class = $this->normalizeType($class);

	$this->RegisterClass($class, $factoryParams, $key);
	$this->factorys[$class][$key] = $factory;
	$this->registerSelfParams($class, $factoryParams, $key);
    }

    /**
     * 直接用实例注册
     * @param string $class 类型全称
     * @param object $instance 工厂方法
     * @param string $key （可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\NotTypeOfInstanceException
     */
    public function RegisterClassByInstance(string $class, object $instance
    , string $key = null)
    {
	$class = $this->normalizeType($class);
	$key = $this->normalizeKey($key);

	$this->RegisterClass($class, [], $key);
	if ($instance instanceof $class)
	{
	    $key = $this->normalizeKey($key);
	    $this->instances[$class][$key] = $instance;
	}
	else
	{
	    throw new DIContainer\NotTypeOfInstanceException($class, $key);
	}
    }

    /**
     * 注册一个接口的实现类（请另外注册该类）
     * @param string $interface 接口全名
     * @param string $class 实现类全名
     * @param string $key （可选）多例模式下interface的key，如果不填则注册为默认实例
     * @param string $classKey （可选）实现类是多例时对应的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\NotExistException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\RegistedException
     */
    public function RegisterInterfaceByClass(string $interface, string $class
    , string $key = null, string $classKey = NULL)
    {
	if ($this->isAbstract($interface))
	{
	    throw new DIContainer\NotExistException($interface, $key);
	}
	elseif ($this->IsRegistered($interface, $key))
	{
	    throw new DIContainer\RegistedException($interface, $key);
	}
	else
	{
	    $key = $this->normalizeKey($key);
	    $classKey = $this->normalizeKey($classKey);

	    $interface = $this->normalizeType($interface);
	    $class = $this->normalizeType($class);

	    $this->registries[$interface][$key] = true;
	    $this->interfaces[$interface][$key] = [$class => $key];
	}
    }

    /**
     * 注册一个接口的实现工厂
     * @param string $interface 接口全称
     * @param \Closure $factory 工厂方法（返回值为实例化结果）
     * @param array $factoryParams （可选）工厂方法的自定参数字典
     * @param string $key （可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\NotExistException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\RegistedException
     */
    public function RegisterInterfaceByFactory(string $interface
    , \Closure $factory, array $factoryParams = [], string $key = null)
    {
	if ($this->isAbstract($interface))
	{
	    throw new DIContainer\NotExistException($interface, $key);
	}
	elseif ($this->IsRegistered($interface, $key))
	{
	    throw new DIContainer\RegistedException($interface, $key);
	}
	else
	{
	    $key = $this->normalizeKey($key);
	    $interface = $this->normalizeType($interface);

	    $this->registries[$interface][$key] = true;
	    $this->factorys[$interface][$key] = $factory;
	    $this->registerSelfParams($interface, $factoryParams, $key);
	}
    }

    /**
     * 注册一个接口的实例
     * @param string $interface 接口全称
     * @param object $instance 实例
     * @param string $key （可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\NotExistException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\RegistedException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\NotTypeOfInstanceException
     */
    public function RegisterInterfaceByInstance(string $interface, object $instance, string $key = null)
    {
	if ($this->isAbstract($interface))
	{
	    throw new DIContainer\NotExistException($interface, $key);
	}
	elseif ($this->IsRegistered($interface, $key))
	{
	    throw new DIContainer\RegistedException($interface, $key);
	}
	elseif (!($instance instanceof $interface))
	{
	    throw new DIContainer\NotTypeOfInstanceException($interface, $key);
	}
	else
	{
	    $key = $this->normalizeKey($key);
	    $interface = $this->normalizeType($interface);

	    $this->registries[$interface][$key] = true;
	    $this->instances[$interface][$key] = $instance;
	}
    }

    /**
     * 清空容器
     */
    public function Clear()
    {
	self::$defaultIOC = null;
	unset($this->buildStack);
	unset($this->factorys);
	unset($this->implemented);
	unset($this->instances);
	unset($this->interfaces);
	unset($this->registries);
	unset($this->selfParams);
    }

    /**
     * 尝试获得指定接口\类型\别名的单例
     * @param string $type 类型或者接口的全称（包括命名空间）
     * @param string $key （可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\NotRegistedException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\DependenceCycleException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\MakeFailedException
     * @return mixed
     */
    public function GetInstance(string $type, string $key = null)
    {
	$type = $this->normalizeType($type);
	$key = $this->normalizeKey($key);

	if (!$this->IsRegistered($type, $key))
	{
	    throw new DIContainer\NotRegistedException($type, $key);
	}

	if ($this->IsImplemented($type, $key))
	{
	    $instance = $this->instances[$type][$key];
	}
	else
	{
	    $parameters = isset($this->selfParams[$type][$key]) ? $this->selfParams[$type][$key] : [];
	    $instance = $this->makeInstance($type, $parameters, $key);
	    $this->instances[$type][$key] = $instance;
	    //将该类型\接口\别名记录为已解决。
	    $this->implemented[$type][$key] = true;
	}
	return $instance;
    }

    /**
     * 获得指定接口\类型\别名的所有已实例化的实例
     * @param string $type 类型或者接口的全称（包括命名空间）
     * @return array 所有实例的集合
     */
    public function GetAllImplementedInstances(string $type)
    {
	$type = $this->normalizeType($type);

	if ($this->HasImplemented($type))
	{
	    $instances = $this->instances[$type];
	}
	else
	{//未实例化的情况下返回空集合
	    $instances = [];
	}

	return $instances;
    }

    /**
     * 尝试获得指定接口\类型的所有实例
     * @param string $type 类或接口的全称
     */
    public function GetAllInstances(string $type)
    {
	$type = $this->normalizeType($type);

	$instances = [];
	if ($this->HasRegisterer($type))
	{
	    foreach ($this->registries[$type] as $key => $value)
	    {
		$instances[] = $this->GetInstance($type, $key);
	    }
	}

	return $instances;
    }

    /**
     * 记录工厂函数\构造函数的自定义参数（如果有）
     * @param string $class 类全名
     * @param array $params
     */
    protected function registerSelfParams(string $class, array $params = [], $key = null)
    {
	if (count($params))
	{
	    $key = $this->normalizeKey($key);
	    //如果自定义数组非空
	    $this->selfParams[$class][$key] = $params;
	}
    }

    /**
     * 根据类型完成实例化
     * @param string $type 类型全称\接口全称\抽象类全称
     * @param array $parameters （可选）自定义实例化参数
     * @param string $key (可选）多例模式下的key
     * @throws \DIServer\Lib\DI\DIContainer\Exception\DependenceCycleException
     * @throws \DIServer\Lib\DI\DIContainer\Exception\MakeFailedException
     */
    protected function makeInstance(string $type, array $parameters = [], $key = null)
    {
	if (in_array($type . '[' . $key . ']', $this->buildStack))
	    throw new Exception\DependenceCycleException($this->buildStack);
	$this->buildStack[] = $type . '[' . $key . ']';
	//实时构造
	if (isset($this->interfaces[$type][$key]))
	{
	    //检查指定映射([$class=>$classKey]
	    $target = $this->interfaces[$type][$key];

	    list($class, $classKey) = each($target);
	    //递归直到实现
	    $instance = $this->GetInstance($class, $classKey);
	}
	elseif (isset($this->factorys[$type][$key]))
	{
	    //尝试使用工厂方法生成
	    $closureFactory = $this->factorys[$type][$key];
	    $instance = $this->buildWithClosure($closureFactory, $parameters);
	}
	elseif (class_exists($type))
	{
	    //尝试使用构造函数生成
	    $instance = $this->buildWithClass($type, $parameters);
	}
	else
	{
	    throw new DIContainer\MakeFailedException($type);
	}
	array_pop($this->buildStack);
	return $instance;
    }

    /**
     * 某个类型\接口是否有实例化的实例
     * @param string $type 类型\接口全名
     * @return bool
     */
    public function HasImplemented(string $type)
    {
	return isset($this->implemented[$type]);
    }

    /**
     * 某个类型\接口是否有被注册过
     * @param string $type 类型\接口全名
     * @return bool
     */
    public function HasRegisterer(string $type)
    {
	return isset($this->registries[$type]);
    }

    /**
     * 指定类型的指定Key是否已经被注册
     * @param string $type 类型名\接口名
     * @param string $key （可选）多例模式下的Key
     * @return type
     */
    public function IsRegistered(string $type, string $key = null)
    {
	$key = $this->normalizeKey($key);
	return isset($this->registries[$type][$key]);
    }

    /**
     * 某个类型的指定Key是否已经被实例化
     * @param string $type 类型全称\接口全称\抽象类全称
     * @param string $key （可选）多例模式下的key
     * @return bool
     */
    protected function IsImplemented(string $type, string $key = null)
    {
	$key = $this->normalizeKey($key);
	return (bool) $this->implemented[$type][$key];
    }

    /**
     * 整理类或者接口的命名
     * @param type $type
     * @return mixed
     */
    protected function normalizeType($type)
    {
	return is_string($type) ? trim($type, '\\') : $type;
    }

    /**
     * 准备好key
     * @param string $key
     * @return string
     */
    protected function normalizeKey(string $key = null)
    {
	return $key? : $this->defaultKey;
    }

    /**
     * 通过匿名工厂函数构造一个对象实例
     * 若工厂函数需要使用参数，会优先选用传入的自定义参数数组
     * 若为未提供自定义参数，会尝试通过type-hint自动从容器实例化
     * 若type-hint实例化失败，会尝试使用该参数的默认值
     * @param \Closure $closure 匿名工厂函数
     * @param array $parameters （可选）工厂函数的自定义参数['$paramName'=>'$instance']
     * @return mixed 构造的实例
     */
    protected function buildWithClosure(\Closure $closure, array $parameters = [])
    {
	/**
	 * 如果传入了一个匿名函数，那么我们直接认为这是一个完整的工厂函数
	 * 直接调用这个函数，并返回执行的结果（不绑定）
	 */
	$funcRef = new \ReflectionFunction($closure);
	return $this->callFunction($funcRef, $parameters);
    }

    /**
     * 根据类名构造一个类的实例
     * 根据构造函数完成依赖注入
     * 若构造函数需要使用参数，会优先选用传入的自定义参数数组
     * 若为未提供自定义参数，会尝试通过type-hint自动从容器实例化
     * 若type-hint实例化失败，会尝试使用该参数的默认值
     * 
     * @param string $className 类的名称
     * @param array $parameters （可选）构造函数中的自定义参数实例
     * @return mixed 类的实例
     * 
     * @throws Exception
     */
    protected function buildWithClass(string $className, array $parameters = [])
    {
	//构造类反射对象
	$classReflector = new \ReflectionClass($className);

	//如果是抽象类或者接口，则无法实例化（异常）
	if (!$classReflector->isInstantiable())
	{
	    $message = "Target $className is not instantiable.";
	    throw new Exception($message);
	}

	//获取类的构造函数的方法反射类	 
	$constructorMetodReflector = $classReflector->getConstructor();

	if ($constructorMetodReflector)
	{
	    //如果构造函数存在，获取这个构造函数的所有参数的依赖项实例并实例化
	    $constructorDependences = $this->getFunctionDependencies($constructorMetodReflector);

	    //根据参数的依赖项实例完成实例化
	    $object = $classReflector->newInstanceArgs($constructorDependences);
	}
	else
	{
	    //构造函数不存在，直接实例化。
	    $object = $classReflector->newInstanceWithoutConstructor();
	}
	return $object;
    }

    /**
     * 函数方法的依赖注入调用
     * 若函数需要使用参数，会优先选用传入的自定义参数数组
     * 若为未提供自定义参数，会尝试通过type-hint自动从容器实例化
     * 若实例化失败，会尝试使用该参数的默认值
     * @param \ReflectionFunction $functionRef 方法的反射实例
     * @param array $parameters （可选）自定义提供的参数-实例列表['$paramName'=>'$instance']
     * @return mixed 方法的返回值
     */
    protected function callFunction(\ReflectionFunction $functionRef, array $parameters = [])
    {
	$res = null;

	$dependencies = $functionRef->getParameters();
	if (empty($dependencies))
	{
	    //没有参数，直接调用
	    $res = $functionRef->invoke();
	}
	else
	{
	    //构造依赖项实例（包括已经由用户提供的）
	    $instances = $this->getFunctionDependencies($functionRef, $parameters);

	    $res = $functionRef->invokeArgs($instances);
	}

	return $res;
    }

    /**
     * 类成员方法的依赖注入调用
     * 
     * @param \ReflectionMethod $methodRef 方法的反射实例
     * @param array $parameters （可选）自定义提供的参数-实例列表['$paramName'=>'$instance']
     * @return mixed 方法的返回值
     */
    protected function callMethod(object $instance, \ReflectionMethod $methodRef, array $parameters = [])
    {
	$res = null;

	$dependencies = $methodRef->getParameters();
	if (empty($dependencies))
	{
	    //没有参数，直接调用
	    $res = $methodRef->invoke();
	}
	else
	{
	    //构造整合依赖项实例（包括已经由用户提供的）
	    $instances = $this->getFunctionDependencies(
		    $dependencies, $parameters
	    );

	    $res = $methodRef->invokeArgs($instance, $instances);
	}
	return $res;
    }

    /**
     * 获取一个函数方法或者成员方法的依赖项参数实例集合
     * 若方法需要使用参数，会优先选用传入的自定义参数数组
     * 若为未提供自定义参数，会尝试通过type-hint自动从容器实例化
     * 若实例化失败，会尝试使用该参数的默认值
     * 
     * @param \ReflectionMethod $abstractFunctionReflector
     * @param array $parameters （可选）自定义提供的参数-实例列表['$paramName'=>'$instance']
     */
    protected function getFunctionDependencies(\ReflectionFunctionAbstract $abstractFunctionReflector, array $parameters = [])
    {
	$dependencies = $abstractFunctionReflector->getParameters();
	if (empty($dependencies))
	{
	    return [];
	}
	else
	{
	    //整合依赖项与自定义参数
	    $parameters = $this->keyParametersByArgument(
		    $dependencies, $parameters
	    );

	    //构造整合依赖项实例（包括已经由用户提供的）
	    $instances = $this->getDependencies(
		    $dependencies, $parameters
	    );

	    return $instances;
	}
    }

    /**
     * 构建['$dependenciesName'=>'$selfDefinedInstance']的映射关系
     * 
     * @param array $dependencies 必须的依赖参数列表[\ReflectionParameter]
     * @param array $parameters 自定义提供的参数-实例列表['$paramName'=>'$instance']
     * @return array ['dependenceName'=>'$instance']
     */
    protected function keyParametersByArgument(array $dependencies, array $parameters)
    {
	foreach ($parameters as $key => $value)
	{
	    if (is_numeric($key))
	    {
		unset($parameters[$key]); //去掉不合法的参数名
		$parameters[$dependencies[$key]->name] = $value;
	    }
	}

	return $parameters;
    }

    /**
     * 根据参数反射，将依赖项全部实例化
     *
     * @param  array  $parameters 参数反射对象集合[\ReflectionParameter]
     * @param  array  $primitives （可选）自己提供的参数实例集合['$paramName'=>'$instance']
     * @return array ['$paramInstance'] 参数对应的实例集合
     */
    protected function getDependencies(array $parameters, array $primitives = [])
    {
	$dependencies = [];

	foreach ($parameters as $parameter)
	{
	    $dependency = $parameter->getClass();
	    if (array_key_exists($parameter->name, $primitives))
	    {
		//由自定义参数实例提供
		$dependencies[] = $primitives[$parameter->name];
	    }
	    elseif (is_null($dependency))
	    {
		//如果class是null，说明可能是标量类型
		$dependencies[] = $this->resolveNonClassParameter($parameter);
	    }
	    else
	    {
		//如果class不是null，尝试依靠容器机制完成实例化
		$dependencies[] = $this->resolveClassParameter($parameter);
	    }
	}

	return (array) $dependencies;
    }

    /**
     * 处理不是对象类型的参数（如标量类型）
     *
     * @param  \ReflectionParameter  $parameter 
     * @return mixed 参数的默认取值
     * @throws \DIServer\Lib\DI\DIContainer\Exception\UnresolvableParameterException 无法获得
     */
    protected function resolveNonClassParameter(\ReflectionParameter $parameter)
    {
	if ($parameter->isDefaultValueAvailable())
	{
	    return $parameter->getDefaultValue();
	}
	else
	{
	    throw new Exception\UnresolvableParameterException($parameter);
	}
    }

    /**
     * 尝试根据参数类型从容器中找到实例
     *
     * @param  \ReflectionParameter  $parameter 参数的反射对象
     * @throws \DIServer\Lib\DI\DIContainer\Exception\UnresolvableParameterException 无法获得
     * @return mixed
     */
    protected function resolveClassParameter(\ReflectionParameter $parameter)
    {
	try
	{
	    return $this->GetInstance($parameter->getClass()->name);
	}
	catch (\DIServer\Lib\DI\DIContainer $ex)
	{
	    //先尝试直接从容器获取对应实例
	    //如果没有再考虑该参数的默认值
	    //要不就挂了
	    if ($parameter->isOptional())
	    {
		return $parameter->getDefaultValue();
	    }
	    throw new Exception\UnresolvableParameterException($parameter);
	}
    }

    /**
     * 工厂函数是否可以用于实例化该类型
     *
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable(\Closure $factory, $type)
    {
	return $concrete === $type || $concrete instanceof \Closure;
    }

    /**
     * 是不是接口或者抽象类
     * @param string $abstract
     * @return boolean
     */
    protected function isAbstract(string $abstract)
    {
	if (!interface_exists($abstract))
	{
	    //如果是抽象类也可以接受
	    $refClass = new ReflectionClass($interface);
	    return $refClass->isAbstract();
	}
	else
	{
	    return true;
	}
    }

}