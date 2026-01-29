# DW WooCommerce CPF Validator

Plugin WordPress/WooCommerce para validação de CPF no checkout usando a API CPF.CNPJ, com foco em compatibilidade com **Brazilian Market on WooCommerce** e **FunnelKit Checkout (WFACP)**.

![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)
![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)

---

## 📋 Índice

- [Sobre](#-sobre)
- [Recursos](#-recursos)
- [Requisitos](#-requisitos)
- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Funcionalidades](#-funcionalidades)
- [Documentação](#-documentação)
- [Perguntas Frequentes](#-perguntas-frequentes)
- [Suporte](#-suporte)
- [Changelog](#-changelog)

---

## 🎯 Sobre

O **DW WooCommerce CPF Validator** é um plugin que valida CPF no checkout em 2 níveis:

- ✅ **Formato/algoritmo** (client-side e server-side)
- ✅ **Consulta na Receita Federal via API** (CPF.CNPJ)

Além de validar, ele melhora a experiência e evita desperdício de créditos:

- 🧠 **Anti-crédito**: evita revalidar o mesmo CPF (cache no front-end + cache no back-end via transient)
- 🔒 **Bloqueio do CPF**: após validação, trava o campo (`readonly`) no checkout atual
- 🧾 **Autofill de Nome/Sobrenome**: quando o pacote retorna **nome completo**, preenche `billing_first_name` e `billing_last_name`
- 🔌 **Compatibilidade**: reutiliza o campo `billing_cpf` de plugins terceiros

---

## ✨ Recursos

### Validação

- ✅ Máscara automática no `billing_cpf` (000.000.000-00)
- ✅ Validação do algoritmo do CPF (antes de consultar a API)
- ✅ Validação via API CPF.CNPJ (AJAX e server-side)

### Economia de créditos

- 🧠 Cache no checkout atual (não chama a API repetidamente para o mesmo CPF)
- 🗄️ Cache no back-end via transient por `(pacote + cpf)` (reduz consumo em refreshs/validações duplicadas)

### Experiência no checkout

- 🔒 CPF pode ficar travado após validar (evita alterações e novas validações)
- 🧾 Preenchimento automático de nome e sobrenome (quando disponível na resposta)
- 🔄 Resiliência a re-render (FunnelKit/WFACP) — restaura campos após refreshs

### Integrações/Compatibilidade

- 🇧🇷 Compatível com o campo `billing_cpf` do plugin [Brazilian Market on WooCommerce](https://wordpress.org/plugins/woocommerce-extra-checkout-fields-for-brazil/)
- 🧩 Compatível com FunnelKit Checkout (WFACP)

---

## 📦 Requisitos

- **WordPress:** 5.8 ou superior
- **WooCommerce:** 5.0 ou superior
- **PHP:** 7.4 ou superior
- **Conta na CPF.CNPJ:** `https://www.cpfcnpj.com.br`
- **Servidor:** permissão para requisições HTTPS externas

---

## 🚀 Instalação

### Método 1: Upload pelo WordPress

1. Baixe o arquivo ZIP do plugin
2. Acesse: `WordPress Admin > Plugins > Adicionar Novo`
3. Clique em: **"Enviar Plugin"**
4. Escolha o arquivo ZIP
5. Clique em: **"Instalar Agora"**
6. Clique em: **"Ativar"**

### Método 2: Upload via FTP

1. Extraia o arquivo ZIP
2. Faça upload da pasta `wc-cpf-validator` para `/wp-content/plugins/`
3. Acesse: `WordPress Admin > Plugins`
4. Encontre: **"DW WooCommerce CPF Validator"**
5. Clique em: **"Ativar"**

### Método 3: Via GitHub

```bash
cd wp-content/plugins/
git clone https://github.com/agenciadw/wc-cpf-validator.git wc-cpf-validator
```

---

## ⚙️ Configuração

### 1. Token da API CPF.CNPJ

1. Crie uma conta em `https://www.cpfcnpj.com.br`
2. Acesse **Painel de Controle → API → Tokens**
3. Gere um token
4. Contrate o pacote desejado

### 2. Configurar no WooCommerce

1. Acesse: `WooCommerce > Configurações > Finalizar compra > Validação CPF`
2. Configure:
   - **Habilitar Validação**
   - **Token da API**
   - **Pacote da API**
   - **Campo Obrigatório**
   - **Validação em Tempo Real**
   - **Salvar Dados da API** (opcional)
   - **Posição/Rótulo/Placeholder** (opcional)

---

## 🎯 Funcionalidades

### Fluxo de validação (resumo)

```
Cliente digita CPF → valida algoritmo → (opcional) valida na API → trava CPF + preenche nome/sobrenome
```

### Compatibilidade com Brazilian Market / FunnelKit

- O plugin usa/reaproveita o campo padrão **`billing_cpf`**.
- No FunnelKit (WFACP), o checkout pode ser re-renderizado; o plugin reaplica o CPF e o nome/sobrenome após refresh.

Referência do FunnelKit sobre compatibilidade com o plugin brasileiro:
[`https://funnelkit.com/docs/checkout-pages/compatibility/woocommerce-extra-checkout-fields-for-brazil`](https://funnelkit.com/docs/checkout-pages/compatibility/woocommerce-extra-checkout-fields-for-brazil)

---

## 📚 Documentação

- API CPF.CNPJ: `https://www.cpfcnpj.com.br`
- Plugin brasileiro (campos): `https://wordpress.org/plugins/woocommerce-extra-checkout-fields-for-brazil/`
- FunnelKit docs (dev): `https://funnelkit.com/docs/checkout-pages/developer-docs/`
- Repositório: `https://github.com/agenciadw/wc-cpf-validator`

---

## ❓ Perguntas Frequentes

### 1. O plugin cria um campo próprio de CPF?

Ele trabalha com o campo **`billing_cpf`**. Se outro plugin (ex.: Brazilian Market) já adicionou o campo, este plugin **reutiliza**.

### 2. Vai consumir muitos créditos da API?

Não, porque o plugin aplica:

- cache no front-end (não revalida o mesmo CPF no mesmo checkout)
- cache no back-end (transient por `(pacote + cpf)`)

### 3. Funciona com FunnelKit?

Sim. Ele foi ajustado para o comportamento do WFACP (re-render do checkout) e para manter o CPF/nome após refreshs.

---

## 🆘 Suporte

Para bugs e sugestões:

- Issues: `https://github.com/agenciadw/wc-cpf-validator/issues`

---

## 📝 Changelog

### Versão 1.0.0

- ✅ Validação de CPF via API CPF.CNPJ
- ✅ Compatibilidade com `billing_cpf`
- ✅ Integração com FunnelKit (WFACP) para evitar “sumir” em re-render
- ✅ Cache para reduzir consumo de créditos
- ✅ Bloqueio do CPF após validação
- ✅ Preenchimento de nome/sobrenome quando disponível na resposta

---

## 📜 Licença

GPL v2 ou posterior.

---

## 🙏 Agradecimentos

- WooCommerce pela plataforma extensível
- Comunidade WordPress pelo ecossistema de plugins

---

## 🚀 Comece Agora!

1. ✅ [Instale o plugin](#-instalação)
2. ✅ [Configure o token da API](#-configuração)
3. ✅ Teste no checkout (idealmente com o `billing_cpf`)
4. ✅ Se usar FunnelKit, garanta que o campo `billing_cpf` esteja nos “Billing Fields”

---

**Versão:** 1.0.1  
**Autor:** David William da Costa - DW Digital  
**Requer:** WordPress 5.8+, WooCommerce 5.0+, PHP 7.4+
