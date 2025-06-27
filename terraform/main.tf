terraform {
  required_version = ">= 1.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.aws_region
}

# Data sources
data "aws_availability_zones" "available" {
  state = "available"
}

# Variables
variable "aws_region" {
  description = "AWS region"
  type        = string
  default     = "us-east-1"
}

variable "environment" {
  description = "Environment name"
  type        = string
  default     = "production"
}

variable "app_name" {
  description = "Application name"
  type        = string
  default     = "whatsapp-business-system"
}

variable "domain_name" {
  description = "Domain name for the application"
  type        = string
  default     = ""
}

# VPC Module
module "vpc" {
  source = "./modules/vpc"
  
  app_name    = var.app_name
  environment = var.environment
  
  vpc_cidr             = "10.0.0.0/16"
  public_subnet_cidrs  = ["10.0.1.0/24", "10.0.2.0/24"]
  private_subnet_cidrs = ["10.0.10.0/24", "10.0.20.0/24"]
  
  availability_zones = slice(data.aws_availability_zones.available.names, 0, 2)
}

# RDS Module
module "rds" {
  source = "./modules/rds"
  
  app_name    = var.app_name
  environment = var.environment
  
  vpc_id              = module.vpc.vpc_id
  private_subnet_ids  = module.vpc.private_subnet_ids
  
  db_instance_class   = "db.t3.micro"
  db_allocated_storage = 20
  db_name             = "whatsapp_business_system"
  db_username         = "laravel"
  
  # Generate random password
  db_password = random_password.db_password.result
}

# ECS Module
module "ecs" {
  source = "./modules/ecs"
  
  app_name    = var.app_name
  environment = var.environment
  
  vpc_id             = module.vpc.vpc_id
  public_subnet_ids  = module.vpc.public_subnet_ids
  private_subnet_ids = module.vpc.private_subnet_ids
  
  db_host     = module.rds.db_endpoint
  db_name     = module.rds.db_name
  db_username = module.rds.db_username
  db_password = module.rds.db_password
  
  redis_endpoint = aws_elasticache_replication_group.redis.primary_endpoint
  
  # Application configuration
  app_env = var.environment
  app_key = random_password.app_key.result
  
  # WhatsApp configuration (to be set via environment variables)
  whatsapp_access_token      = var.whatsapp_access_token
  whatsapp_phone_number_id   = var.whatsapp_phone_number_id
  whatsapp_business_account_id = var.whatsapp_business_account_id
  whatsapp_webhook_verify_token = var.whatsapp_webhook_verify_token
  whatsapp_app_secret        = var.whatsapp_app_secret
  
  # Gemini AI configuration
  gemini_api_key = var.gemini_api_key
}

# ElastiCache Redis
resource "aws_elasticache_subnet_group" "redis" {
  name       = "${var.app_name}-${var.environment}-redis-subnet-group"
  subnet_ids = module.vpc.private_subnet_ids
}

resource "aws_security_group" "redis" {
  name_prefix = "${var.app_name}-${var.environment}-redis-"
  vpc_id      = module.vpc.vpc_id

  ingress {
    from_port   = 6379
    to_port     = 6379
    protocol    = "tcp"
    cidr_blocks = [module.vpc.vpc_cidr]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name        = "${var.app_name}-${var.environment}-redis-sg"
    Environment = var.environment
  }
}

resource "aws_elasticache_replication_group" "redis" {
  replication_group_id       = "${var.app_name}-${var.environment}-redis"
  description                = "Redis cluster for ${var.app_name}"
  
  node_type                  = "cache.t3.micro"
  port                       = 6379
  parameter_group_name       = "default.redis7"
  
  num_cache_clusters         = 2
  automatic_failover_enabled = true
  multi_az_enabled          = true
  
  subnet_group_name = aws_elasticache_subnet_group.redis.name
  security_group_ids = [aws_security_group.redis.id]
  
  at_rest_encryption_enabled = true
  transit_encryption_enabled = true
  auth_token                 = random_password.redis_password.result
  
  tags = {
    Name        = "${var.app_name}-${var.environment}-redis"
    Environment = var.environment
  }
}

# Random passwords
resource "random_password" "db_password" {
  length  = 32
  special = true
}

resource "random_password" "app_key" {
  length  = 32
  special = false
}

resource "random_password" "redis_password" {
  length  = 32
  special = true
}

# WhatsApp configuration variables
variable "whatsapp_access_token" {
  description = "WhatsApp Business API access token"
  type        = string
  sensitive   = true
}

variable "whatsapp_phone_number_id" {
  description = "WhatsApp phone number ID"
  type        = string
}

variable "whatsapp_business_account_id" {
  description = "WhatsApp Business Account ID"
  type        = string
}

variable "whatsapp_webhook_verify_token" {
  description = "WhatsApp webhook verify token"
  type        = string
  sensitive   = true
}

variable "whatsapp_app_secret" {
  description = "WhatsApp app secret"
  type        = string
  sensitive   = true
}

variable "gemini_api_key" {
  description = "Google Gemini API key"
  type        = string
  sensitive   = true
}

# Outputs
output "application_url" {
  description = "Application URL"
  value       = module.ecs.application_url
}

output "database_endpoint" {
  description = "RDS endpoint"
  value       = module.rds.db_endpoint
  sensitive   = true
}

output "redis_endpoint" {
  description = "Redis endpoint"
  value       = aws_elasticache_replication_group.redis.primary_endpoint
  sensitive   = true
}

