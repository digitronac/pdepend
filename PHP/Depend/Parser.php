<?php
/**
 * This file is part of PHP_Depend.
 * 
 * PHP Version 5
 *
 * Copyright (c) 2008, Manuel Pichler <mapi@pmanuel-pichler.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Manuel Pichler nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category  QualityAssurance
 * @package   PHP_Depend
 * @author    Manuel Pichler <mapi@manuel-pichler.de>
 * @copyright 2008 Manuel Pichler. All rights reserved.
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   SVN: $Id$
 * @link      http://www.manuel-pichler.de/
 */

require_once 'PHP/Depend/Code/VisibilityAwareI.php';
require_once 'PHP/Depend/Code/NodeBuilder.php';
require_once 'PHP/Depend/Code/Tokenizer.php';

/**
 * The php source parser.
 * 
 * @category  QualityAssurance
 * @package   PHP_Depend
 * @author    Manuel Pichler <mapi@manuel-pichler.de>
 * @copyright 2008 Manuel Pichler. All rights reserved.
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version   Release: @package_version@
 * @link      http://www.manuel-pichler.de/
 */
class PHP_Depend_Parser
{
    /**
     * Last parsed package tag.
     *
     * @type string
     * @var string $package
     */
    protected $package = null;
    
    /**
     * Marks the current class as abstract.
     *
     * @type boolean
     * @var boolean $abstract
     */
    protected $abstract = false;
    
    /**
     * The name of the context class.
     *
     * @type string 
     * @var string $className
     */
    protected $className = '';
    
    /**
     * The used code tokenizer.
     *
     * @type PHP_Depend_Code_Tokenizer 
     * @var PHP_Depend_Code_Tokenizer $tokenizer
     */
    protected $tokenizer = null;
    
    /**
     * The used data structure builder.
     * 
     * @type PHP_Depend_Code_NodeBuilder
     * @var PHP_Depend_Code_NodeBuilder $builder
     */
    protected $builder = null;
    
    protected $visibilityMap = array(
        
    );
    
    /**
     * Constructs a new source parser.
     *
     * @param PHP_Depend_Code_Tokenizer   $tokenizer The used code tokenizer.
     * @param PHP_Depend_Code_NodeBuilder $builder   The used node builder.
     */
    public function __construct(PHP_Depend_Code_Tokenizer $tokenizer, 
                                PHP_Depend_Code_NodeBuilder $builder)
    {
        $this->tokenizer = $tokenizer;
        $this->builder   = $builder;
    }
    
    /**
     * Parses the contents of the tokenizer and generates a node tree based on
     * the found tokens.
     *
     * @return void
     */
    public function parse()
    {
        $this->reset();
        
        $comment = null;
        
        while (($token = $this->tokenizer->next()) !== PHP_Depend_Code_Tokenizer::T_EOF) {
            
            switch ($token[0]) {
            case PHP_Depend_Code_Tokenizer::T_ABSTRACT:
                $this->abstract = true;
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_DOC_COMMENT:
                $comment       = $token[1];
                $this->package = $this->parsePackage($token[1]);
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_INTERFACE:
                // Get interface name
                $token = $this->tokenizer->next();
                    
                $this->className = $token[1];

                $interface = $this->builder->buildInterface($this->className, $token[2]);
                $interface->setSourceFile($this->tokenizer->getSourceFile());
                $interface->setStartLine($token[2]);
                $interface->setDocComment($comment);
                
                $this->parseInterfaceSignature($interface);

                $this->builder->buildPackage($this->package)->addType($interface);

                $this->parseTypeBody($interface);
                $this->reset();
                
                $comment = null;
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_CLASS:
                // Get class name
                $token = $this->tokenizer->next();
                    
                $this->className = $token[1];

                $class = $this->builder->buildClass($this->className, $token[2]);
                $class->setSourceFile($this->tokenizer->getSourceFile());
                $class->setStartLine($token[2]);
                $class->setAbstract($this->abstract);
                $class->setDocComment($comment);
                
                $this->parseClassSignature($class);

                $this->builder->buildPackage($this->package)->addType($class);

                $this->parseTypeBody($class);
                $this->reset();
                
                $comment = null;
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_FUNCTION:
                $function = $this->parseCallable();
                $function->setSourceFile($this->tokenizer->getSourceFile());
                $function->setDocComment($comment);
                
                $comment = null;
                break;
                    
            default:
                // TODO: Handle/log unused tokens
                $comment = null;
                break;
            }
        }
    }
    
    /**
     * Resets some object properties.
     *
     * @return void
     */
    protected function reset()
    {
        $this->package   = PHP_Depend_Code_NodeBuilder::DEFAULT_PACKAGE;
        $this->abstract  = false;
        $this->className = null;
    }
    
    /**
     * Parses the dependencies in a interface signature.
     * 
     * @param PHP_Depend_Code_Interface $interface The context interface instance.
     *
     * @return void
     */
    protected function parseInterfaceSignature(PHP_Depend_Code_Interface $interface)
    {
        while ($this->tokenizer->peek() !== PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_OPEN) {
            $token = $this->tokenizer->next();
            if ($token[0] === PHP_Depend_Code_Tokenizer::T_STRING) {
                $dependency = $this->builder->buildInterface($token[1]);
                $interface->addDependency($dependency);
            }
        }
    }
    
    /**
     * Parses the dependencies in a class signature.
     * 
     * @param PHP_Depend_Code_Class $class The context class instance.
     *
     * @return void
     */
    protected function parseClassSignature(PHP_Depend_Code_Class $class)
    {
        $implements = false;
        while ($this->tokenizer->peek() !== PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_OPEN) {
            $token = $this->tokenizer->next();
            if ($token[0] === PHP_Depend_Code_Tokenizer::T_IMPLEMENTS) {
                $implements = true;
            } else if ($token[0] === PHP_Depend_Code_Tokenizer::T_STRING) {
                if ($implements) {
                    $dependency = $this->builder->buildInterface($token[1]);
                } else {
                    $dependency = $this->builder->buildClass($token[1]);
                }
                // Set class dependency
                $class->addDependency($dependency);
            }
        }
    }
    
    /**
     * Parses a class/interface body.
     * 
     * @param PHP_Depend_Code_AbstractType $type The context type instance.
     *
     * @return void
     */
    protected function parseTypeBody(PHP_Depend_Code_AbstractType $type)
    {
        $token = $this->tokenizer->next();
        $curly = 0;
        
        $visibilty = PHP_Depend_Code_VisibilityAwareI::IS_PUBLIC;;
        $comment   = null;
        $abstract  = false;
        
        while ($token !== PHP_Depend_Code_Tokenizer::T_EOF) {
            
            switch ($token[0]) {
            case PHP_Depend_Code_Tokenizer::T_FUNCTION:
                $method = $this->parseCallable($type);
                $method->setDocComment($comment);
                $method->setAbstract($abstract);
                $method->setVisibility($visibilty);
                
                $visibilty = PHP_Depend_Code_VisibilityAwareI::IS_PUBLIC;;
                $comment   = null;
                $abstract  = false;
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_OPEN:
                ++$curly;
                $comment = null; 
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_CLOSE:
                --$curly;
                $comment = null; 
                break;
                
            case PHP_Depend_Code_Tokenizer::T_ABSTRACT:
                $abstract = true;
                break;
                
            case PHP_Depend_Code_Tokenizer::T_PUBLIC:
                $visibilty = PHP_Depend_Code_VisibilityAwareI::IS_PUBLIC;
                break;
                
            case PHP_Depend_Code_Tokenizer::T_PRIVATE:
                $visibilty = PHP_Depend_Code_VisibilityAwareI::IS_PRIVATE;
                break;
                
            case PHP_Depend_Code_Tokenizer::T_PROTECTED:
                $visibilty = PHP_Depend_Code_VisibilityAwareI::IS_PROTECTED;
                break;
                
            case PHP_Depend_Code_Tokenizer::T_STATIC:
                break;
                
            case PHP_Depend_Code_Tokenizer::T_FINAL:
                break;
                
            case PHP_Depend_Code_Tokenizer::T_DOC_COMMENT:
                $comment = $token[1];
                break;
            
            default:
                // TODO: Handle/log unused tokens
                $comment = null; 
                break;
            }
            
            if ($curly === 0) {
                // Set end line number 
                $type->setEndLine($token[2]);
                // Stop processing
                break;
            } else {
                $token = $this->tokenizer->next();
            }
        }
        
        if ($curly !== 0) {
            throw new RuntimeException('Invalid state, unclosed class body.');
        }
    }
    
    /**
     * Parses a function or a method and adds it to the parent context node.
     * 
     * @param PHP_Depend_Code_AbstractType $parent An optional parent interface of class.
     * 
     * @return PHP_Depend_Code_Callable
     */
    protected function parseCallable(PHP_Depend_Code_AbstractType $parent = null)
    {
        $token = $this->tokenizer->next();
        if ($token[0] === PHP_Depend_Code_Tokenizer::T_BITWISE_AND) {
            $token = $this->tokenizer->next();
        }
        
        $callable = null;
        if ($parent === null) {
            $callable = $this->builder->buildFunction($token[1], $token[2]);
            $this->builder->buildPackage($this->package)->addFunction($callable); 
        } else {
            $callable = $this->builder->buildMethod($token[1], $token[2]);
            $parent->addMethod($callable);
        }
        
        $this->parseCallableSignature($callable);
        if ($this->tokenizer->peek() === PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_OPEN) {
            // Get function body dependencies 
            $this->parseCallableBody($callable);
        } else {
            $callable->setEndLine($token[2]);
        }
        
        return $callable;
    }

    /**
     * Extracts all dependencies from a callable signature.
     *
     * @param PHP_Depend_Code_AbstractCallable $callable The context callable.
     * 
     * @return void
     */
    protected function parseCallableSignature(PHP_Depend_Code_AbstractCallable $callable)
    {
        if ($this->tokenizer->peek() !== PHP_Depend_Code_Tokenizer::T_PARENTHESIS_OPEN) {
            // Load invalid token for line number
            $token = $this->tokenizer->next();
            
            // Throw a detailed exception message
            throw new RuntimeException(
                sprintf(
                    'Invalid token "%s" on line %s in file: %s.',
                    $token[1],
                    $token[2],
                    $this->tokenizer->getSourceFile()
                )
            );
        }

        $parenthesis = 0;
        
        while (($token = $this->tokenizer->next()) !== PHP_Depend_Code_Tokenizer::T_EOF) {

            switch ($token[0]) {
            case PHP_Depend_Code_Tokenizer::T_PARENTHESIS_OPEN:
                ++$parenthesis;
                break;
                 
            case PHP_Depend_Code_Tokenizer::T_PARENTHESIS_CLOSE:
                --$parenthesis;
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_STRING:
                // Create an instance for this dependency and append it
                $dependency = $this->builder->buildClassOrInterface($token[1]);
                $callable->addDependency($dependency);
                break;

            default:
                // TODO: Handle/log unused tokens
            }
            
            if ($parenthesis === 0) {
                return;
            }
        }
        throw new RuntimeException('Invalid function signature.');
    }
    
    /**
     * Extracts all dependencies from a callable body.
     *
     * @param PHP_Depend_Code_AbstractCallable $callable The context callable.
     * 
     * @return void
     */
    protected function parseCallableBody(PHP_Depend_Code_AbstractCallable $callable)
    {
        $curly  = 0;
        $tokens = array();

        while ($this->tokenizer->peek() !== PHP_Depend_Code_Tokenizer::T_EOF) {
            
            $tokens[] = $token = $this->tokenizer->next();

            switch ($token[0]) {
            case PHP_Depend_Code_Tokenizer::T_NEW:
                $allowed = array(
                    PHP_Depend_Code_Tokenizer::T_DOUBLE_COLON,
                    PHP_Depend_Code_Tokenizer::T_STRING,
                );
                
                $parts = array();
                while (in_array($this->tokenizer->peek(), $allowed)) {
                    $token    = $this->tokenizer->next();
                    $tokens[] = $token;
                    
                    if ($token[0] === PHP_Depend_Code_Tokenizer::T_STRING) {
                        $parts[] = $token[1];
                    }
                }
                
                // Get last element of parts and create a class for it
                $class = $this->builder->buildClass(join('::', $parts));
                
                $callable->addDependency($class);
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_STRING:
                if ($this->tokenizer->peek() === PHP_Depend_Code_Tokenizer::T_DOUBLE_COLON) {
                    // Skip double colon
                    $tokens[] = $this->tokenizer->next();
                    // Check for method call
                    if ($this->tokenizer->peek() === PHP_Depend_Code_Tokenizer::T_STRING) {
                        // Skip method call
                        $tokens[] = $this->tokenizer->next();
                        // Create a dependency class
                        $dependency = $this->builder->buildClassOrInterface($token[1]);

                        $callable->addDependency($dependency);
                    }
                }
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_OPEN:
                ++$curly;
                break;
                    
            case PHP_Depend_Code_Tokenizer::T_CURLY_BRACE_CLOSE:
                --$curly;
                break;

            default:
                // TODO: Handle/log unused tokens
            }
            
            if ($curly === 0) {
                // Set end line number
                $callable->setEndLine($token[2]);
                // Set all tokens for this function
                $callable->setTokens(array_slice($tokens, 1, count($tokens) - 2));
                // Stop processing
                break;
            }
        }
        // Throw an exception for invalid states
        if ($curly !== 0) {
            throw new RuntimeException('Invalid state, unclosed function body.');
        }
    }
    
    /**
     * Extracts the @package information from the given comment.
     *
     * @param string $comment A doc comment block.
     * 
     * @return string
     */
    protected function parsePackage($comment)
    {
        if (preg_match('#\*\s*@package\s+(.*)#', $comment, $match)) {
            return trim($match[1]);
        }
        return PHP_Depend_Code_NodeBuilder::DEFAULT_PACKAGE;
    }    
}