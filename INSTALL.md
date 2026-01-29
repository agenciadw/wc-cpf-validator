# Guia de Instalação Rápida - WooCommerce CPF Validator

## 🚀 Instalação em 5 Minutos

### Passo 1: Baixar o Plugin
1. Baixe todos os arquivos do plugin
2. Coloque-os em uma pasta chamada `wc-cpf-validator`

### Passo 2: Fazer Upload
1. Compacte a pasta `wc-cpf-validator` em formato .zip
2. No WordPress, vá em **Plugins > Adicionar novo**
3. Clique em **Fazer upload de plugin**
4. Selecione o arquivo .zip
5. Clique em **Instalar agora** e depois **Ativar**

### Passo 3: Obter Token da API
1. Acesse https://www.cpfcnpj.com.br/register/
2. Crie sua conta gratuitamente
3. Faça login no Painel de Controle
4. Vá em **API > Tokens**
5. Clique em **Gerar Token**
6. Copie o token gerado

### Passo 4: Contratar Pacote
1. No Painel da CPF.CNPJ, vá em **Pacotes**
2. Escolha o pacote de CPF desejado (veja tabela abaixo)
3. Faça a contratação ou adicione créditos

### Passo 5: Configurar Plugin
1. No WordPress, vá em **WooCommerce > Configurações**
2. Clique na aba **Finalizar compra**
3. Clique em **Validação CPF**
4. Configure:
   - ✅ Marque "Habilitar Validação"
   - 📝 Cole seu token no campo "Token da API"
   - 📦 Selecione o pacote contratado
   - ✅ Configure outras opções conforme necessário
5. Clique em **Salvar alterações**

## ✅ Pronto!

Agora seu checkout terá validação de CPF funcionando!

## 📊 Qual Pacote Escolher?

### Para E-commerce Básico
**CPF A** (R$ 0,15) - Apenas nome
- Ideal para: Lojas simples que só precisam confirmar o nome

### Para E-commerce Padrão
**CPF B** (R$ 0,22) - Nome + Data de Nascimento
- Ideal para: Maioria das lojas online
- Boa relação custo-benefício

### Para E-commerce com Compliance
**CPF D** (R$ 0,36) - Dados completos + Situação + Comprovante
- Ideal para: Lojas que precisam de comprovação legal
- Inclui situação cadastral e PDF de comprovante
- Consulta em tempo real com a Receita Federal

### Para Alto Nível de Segurança
**CPF E** (R$ 0,47) - Tudo do CPF D + Mãe + Gênero
- Ideal para: Produtos/serviços de alto valor
- Máxima verificação de identidade

## 🧪 Testar Antes de Usar

### Modo de Teste Gratuito
1. Nas configurações, marque **"Modo de Teste"**
2. Não precisa inserir token
3. Retorna dados fictícios para testes
4. Não consome créditos

### Token de Teste
```
5ae973d7a997af13f0aaf2bf60e65803
```

### CPF para Teste
```
000.000.000-00
```

## 📱 Testar no Checkout

1. Vá para seu site
2. Adicione um produto ao carrinho
3. Vá para o checkout
4. Preencha o campo CPF
5. Veja a validação acontecer em tempo real!

## ⚙️ Configurações Recomendadas

### Para Melhor Experiência do Usuário:
- ✅ Campo Obrigatório: Sim
- ✅ Validação em Tempo Real: Sim
- ✅ Salvar Dados da API: Sim
- 📍 Posição do Campo: Depois do Email
- 📝 Rótulo: "CPF"
- 📋 Placeholder: "000.000.000-00"

### Para Depuração:
- ✅ Log de Erros: Sim (apenas durante setup)
- ❌ Modo de Teste: Não (após testar)

## 🔧 Verificar se está Funcionando

### Checklist:
- [ ] Plugin ativado
- [ ] Token configurado
- [ ] Pacote selecionado
- [ ] Campo aparece no checkout
- [ ] Máscara funciona (formatação automática)
- [ ] Validação em tempo real funciona
- [ ] Mensagens aparecem (válido/inválido)
- [ ] CPF salvo no pedido
- [ ] CPF aparece na lista de pedidos

## ❓ Problemas Comuns

### Campo CPF não aparece
**Solução**: Limpe o cache do site e verifique se o plugin está ativo

### Erro "Token inválido"
**Solução**: Verifique se copiou o token completo sem espaços

### Erro "Créditos insuficientes"
**Solução**: Adicione créditos em https://www.cpfcnpj.com.br/admin

### Validação muito lenta
**Solução**: API tem tempo médio de 2s, é normal. Certifique-se que seu servidor permite HTTPS externo

### CPF válido é rejeitado
**Solução**: 
1. Verifique se o CPF realmente existe na Receita
2. Confirme se está usando o pacote correto
3. Teste com CPF próprio

## 📊 Monitorar Uso

### Ver Saldo de Créditos:
1. Vá em **WooCommerce > Configurações > Validação CPF**
2. Verá seu saldo no topo da página

### Ver Log de Erros:
1. Vá em **WooCommerce > Status > Logs**
2. Selecione o log `wc-cpf-validator`

## 🎯 Próximos Passos

Após instalação:
1. ✅ Teste com CPF real
2. 📊 Monitore o saldo
3. 🔍 Verifique os pedidos
4. 📈 Analise os dados coletados
5. 🎨 Personalize mensagens e posição do campo

## 💡 Dicas de Uso

### Para Economizar Créditos:
- Use pacotes mais simples quando possível
- Desative validação em tempo real se não for necessária
- Use cache de CPFs já validados (implementação customizada)

### Para Melhor Segurança:
- Use pacotes com situação cadastral
- Salve dados da API para auditoria
- Configure log de erros
- Monitore CPFs suspensos/cancelados

## 📞 Suporte

### Problemas com Plugin:
- GitHub Issues

### Problemas com API:
- Email: suporte@cpfcnpj.com.br
- Base: https://suporte.cpfcnpj.com.br

### Problemas com WordPress:
- Fórum WordPress

---

**Desenvolvido para integração com a API CPF.CNPJ**
