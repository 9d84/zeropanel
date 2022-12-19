### Hi there 👋

<!--
**zeropanel/zeropanel** is a ✨ _special_ ✨ repository because its `README.md` (this file) appears on your GitHub profile.

Here are some ideas to get you started:

- 🔭 I’m currently working on ...
- 🌱 I’m currently learning ...
- 👯 I’m looking to collaborate on ...
- 🤔 I’m looking for help with ...
- 💬 Ask me about ...
- 📫 How to reach me: ...
- 😄 Pronouns: ...
- ⚡ Fun fact: ...
-->

# ZeroPanel基于SSPanel魔改
## 新功能
    1. 重构商品购买逻辑
    2. 重构订单系统
    3. 支持自定义货币单位
    4. 大量系统设置数据库化
    5. 多语言功能
    6. 夜间模式
    7. 自定义landing页面
## 演示网站
http://zeroboard.top
## 安装教程（基于debian11）
#### 安装环境
1.nginx最新版  
2.php8.1  
3.mariadb最新版  
#### 第一步
    git clone https://github.com/zeropanel/zeropanel.git ${PWD}
#### 第二步
    wget https://getcomposer.org/installer -O composer.phar
    php composer.phar
    php composer.phar install
#### 第三步
    chmod -R 755${PWD}
    chown -R www-data:www-data ${PWD}
#### 第四步，创建数据库
    mysql -u root -p
    CREATE DATABASE zeropanel;
    use zeropanel;
    CREATE USER 'zeropanel'@'localhost' IDENTIFIED BY 'shezhizijidemima';
    GRANT ALL PRIVILEGES ON *.* TO 'zeropanel'@'localhost';
    FLUSH PRIVILEGES;
    source /var/www/zeropanel/sql/zero.sql;
#### 第五步，配置Nginx
    cd /etc/nginx
    vim enabled-sites/zeropanel.conf
##### 复制以下文件到nginx配置文件中
    server {
        listen 80;
        listen [::]:80;
        root /var/www/zeropanel/public;
        index index.php index.html;
        server_name 你的域名;
        location / {
            try_files $uri /index.php$is_args$args;
        }   
    
        location ~ \.php$ {
            include fastcgi.conf;
            fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        }
    }
##### 重启nginx和php-fpm
    systemctl restart nginx
    systemctl restart php8.1-fpm
#### 配置定时任务
    crontab -e
##### 复制以下文件到定时任务中
    * * * * * php /var/www/zeropanel/xcat Job CheckJob
    0 0 * * * php /var/www/zeropanel/xcat Job DailyJob
    0 * * * * php /var/www/zeropanel/xcat Job UserJob
    * * * * * php /var/www/zeropanel/xcat Job CheckUserExpire
    * * * * * php /var/www/zeropanel/xcat Job CheckUserClassExpire
    * * * * * php /var/www/zeropanel/xcat Job SendMail
    * * * * * php /var/www/zeropanel/xcat Job CheckOrderStatus
## 交流
https://t.me/zero_panel_group
    欢迎各位大佬PR，以及参与测试提交问题
