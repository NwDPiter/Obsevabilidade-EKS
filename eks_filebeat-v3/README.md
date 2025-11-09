# 📘 Documentação Completa: Arquitetura ELK + WordPress + Nginx + Filebeat

## 🗂️ Estrutura de Diretórios

```
├── filebeat/           # Configurações do Filebeat
│   └── modules.d/      # Módulos ativados (ex: nginx.yml)
├── kibana/config/      # Configuração do Kibana
├── nginx/              # Configuração e logs do Nginx
│   ├── conf.d/         # Arquivos de configuração por site (ex: default.conf)
│   └── logs/           # Logs de acesso e erro do Nginx
└── wordpress/          # Arquivos e conteúdo do WordPress
    ├── logs/           # Logs da aplicação WordPress
    └── wp-content/     # Plugins, temas e uploads
```

---

## ⚙️ Arquivos de Configuração

### 🔹 `filebeat/filebeat.yml`

Configura o Filebeat para coletar logs e enviar ao Elasticsearch.

```yaml
filebeat.inputs:
- type: log
  enabled: true
  paths:
    - /var/log/nginx/access.log
```
- **type: log**: define o tipo de entrada.
- **enabled: true**: ativa a coleta.
- **paths**: caminho do arquivo de log a ser monitorado.

```yaml
filebeat.config.modules:
  path: ${path.config}/modules.d/*.yml
  reload.enabled: false
```
- **path**: onde estão os módulos ativados.
- **reload.enabled**: se `true`, recarrega módulos dinamicamente.

```yaml
setup.kibana:
  host: "http://kibana:5601"
setup.dashboards.enabled: true
```
- Conecta ao Kibana e ativa o carregamento automático de dashboards.

```yaml
output.elasticsearch:
  hosts: ["http://elasticsearch:9200"]
```
- Define o destino dos logs: o Elasticsearch.

---

### 🔹 `filebeat/modules.d/nginx.yml`

Ativa o módulo Nginx para parsing automático dos logs.

```yaml
- module: nginx
  access:
    enabled: true
    var.paths: ["/var/log/nginx/access.log"]
```
- **module: nginx**: ativa o módulo.
- **access.enabled**: ativa coleta de logs de acesso.
- **var.paths**: caminho do log de acesso.

---

### 🔹 `kibana/config/kibana.yml`

Configura o Kibana para se conectar ao Elasticsearch.

```yaml
server.name: kibana
server.host: "0.0.0.0"
elasticsearch.hosts: ["http://elasticsearch:9200"]
monitoring.ui.container.elasticsearch.enabled: true
```
- **server.name**: nome do servidor Kibana.
- **server.host**: escuta em todas interfaces.
- **elasticsearch.hosts**: URL do Elasticsearch.
- **monitoring.ui.container.elasticsearch.enabled**: ativa monitoramento via UI.

---

### 🔹 `nginx/nginx.conf`

Configuração principal do Nginx.

```nginx
log_format main "$remote_addr - $remote_user [$time_local] \"$request\" "
                "$status $body_bytes_sent \"$http_referer\" "
                "\"$http_user_agent\" \"$http_accept_language\"";
access_log /var/log/nginx/access.log main;
include /etc/nginx/conf.d/*.conf;
```
- **log_format**: inclui idioma do navegador para análise geográfica.
- **access_log**: define onde salvar os logs.
- **include**: carrega configurações adicionais por site.

---

### 🔹 `nginx/conf.d/default.conf`

Configura o proxy reverso para o WordPress.

```nginx
location / {
  proxy_pass http://wordpress:80;
  proxy_set_header Host $host;
  proxy_set_header X-Real-IP $remote_addr;
  proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  proxy_set_header X-Request-ID $request_id;
}
```
- Redireciona requisições para o container WordPress.
- Adiciona headers úteis para rastreamento e analytics.

---

### 🔹 `compose.yml`

Orquestra todos os serviços com Docker Compose.

#### 🔸 `nginx`
- **image: nginx:alpine**: versão leve do Nginx.
- **volumes**: monta configs e logs.
- **depends_on**: espera WordPress e Kibana estarem prontos.

#### 🔸 `elasticsearch`
- **image**: versão 8.11.0.
- **xpack.security.enabled=false**: desativa autenticação.
- **volumes**: persistência de dados.

#### 🔸 `kibana`
- Conecta ao Elasticsearch via variável `ELASTICSEARCH_HOSTS`.

#### 🔸 `filebeat`
- Coleta logs do Nginx e envia ao Elasticsearch.
- **command**: copia e ajusta permissões do `filebeat.yml`.
- **healthcheck**: garante que o Filebeat está funcional.

#### 🔸 `mysql` + `wordpress`
- Banco de dados e aplicação WordPress.
- Volumes para persistência e plugins/temas.

#### 🔸 `networks`
- **elk-network**: conecta ELK Stack.
- **wordpress-network**: conecta WordPress e MySQL.

---

## ✅ Como subir a arquitetura

1. Instale Docker e Docker Compose.
2. Crie os diretórios conforme a estrutura.
3. Salve os arquivos de configuração nos locais indicados.
4. Execute:

```bash
docker-compose up -d
```

5. Acesse:
   - WordPress: `http://localhost`
   - Kibana: `http://localhost:5601`

---

## 🔌 Ativação do módulo Nginx no Filebeat

O Filebeat possui módulos pré-configurados para serviços populares como Nginx, Apache, MySQL, etc. Esses módulos facilitam o parsing dos logs e a criação automática de dashboards e pipelines no Elasticsearch.

### ✅ Objetivo

Ativar o módulo `nginx` para que o Filebeat:

- Reconheça o formato dos logs de acesso e erro
- Extraia campos estruturados (IP, URL, status HTTP, idioma, etc.)
- Envie os dados corretamente para o Elasticsearch
- Gere dashboards prontos no Kibana

---

### 🧭 Etapas realizadas dentro do container do Filebeat

1. **Acessar o container**:

```bash
docker exec -it filebeat bash
```

2. **Listar os módulos disponíveis**:

```bash
filebeat modules list
```

> Isso mostra quais módulos estão disponíveis e quais estão ativados.

3. **Ativar o módulo Nginx**:

```bash
filebeat modules enable nginx
```

> Esse comando cria o arquivo `modules.d/nginx.yml` com a configuração padrão.

4. **Verificar o conteúdo do módulo ativado**:

```bash
cat modules.d/nginx.yml
```

Resultado esperado:

```yaml
- module: nginx
  access:
    enabled: true
    var.paths: ["/var/log/nginx/access.log"]
```

> Esse caminho deve existir e estar montado corretamente no container.

5. **Executar o setup para carregar dashboards e pipelines**:

```bash
filebeat setup --modules nginx
```

Esse comando:

- Cria os pipelines de ingestão no Elasticsearch
- Carrega os dashboards no Kibana
- Configura os templates de índice

---

### 📌 Observações importantes

- O módulo só funciona se o `filebeat.yml` tiver a seção:

```yaml
filebeat.config.modules:
  path: ${path.config}/modules.d/*.yml
  reload.enabled: false
```

- O caminho `var.paths` deve apontar para o log real dentro do container (`/var/log/nginx/access.log`)
- Após ativar o módulo, é necessário reiniciar o Filebeat para aplicar as mudanças:

```bash
docker-compose restart filebeat
```
---
## 📊 Visualização dos logs no Kibana

### ✅ Cenário 1: índice `filebeat-*` já aparece no Discover

Se você já vê o índice `filebeat-*` listado em Discover:

1. Acesse **Kibana > Discover**
2. Selecione o índice `filebeat-*`
3. Use o campo `@timestamp` como filtro de tempo
4. Explore os campos como:
   - `nginx.access.remote_ip`
   - `nginx.access.url`
   - `nginx.access.http_response_code`
   - `user_agent.name`
   - `geoip.country_name`

> Você também pode aplicar filtros por país, idioma ou status HTTP para análises específicas.

---

### 🧭 Cenário 2: índice `filebeat-*` ainda não aparece

Se o índice não está disponível, siga este passo a passo:

1. Acesse **Kibana > Stack Management > Index Patterns**
2. Clique em **"Create index pattern"**
3. No campo de nome, digite: `filebeat-*`
4. Clique em **"Next step"**
5. Selecione o campo de tempo: `@timestamp`
6. Clique em **"Create index pattern"**

Agora:

- Vá para **Discover**
- Selecione o novo padrão `filebeat-*`
- Os logs começarão a aparecer conforme o Filebeat envia eventos

> Se ainda não aparecerem dados, verifique se o Filebeat está colhendo eventos (`filebeat test output`) e se o Elasticsearch está recebendo (`GET _cat/indices`).

---
