# GLPI SAS para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

**Controle administrativo** do GLPI: limita quanto cada perfil, entidade ou grupo pode criar num período, controla **horas contratadas** e registra tudo para auditoria. É o sucessor do plugin "Controle Administrativo".

## O que o plugin faz

### Limites por período (mês, ano ou total)
| Alvo | O que pode ser limitado |
|---|---|
| **Perfil** | usuários, grupos e perfis criados por quem tem o perfil |
| **Entidade** | chamados, mudanças, problemas e projetos |
| **Grupo** | membros e chamados |

- A contagem usa os itens reais do GLPI; itens na lixeira não contam.
- Ao atingir o limite, a criação é **bloqueada** com uma mensagem configurável.
- **Aviso** antecipado ao chegar perto do limite, com o percentual configurável.
- Perfis isentos.

### Horas contratadas
- Por **entidade**, **grupo** ou **usuário**, com quantidade de horas e **valores**.
- As horas gastas vêm do tempo das tarefas ou do intervalo entre o atendimento e a solução, descontando as pausas.
- Detalhamento das horas por chamado.

### Painel e relatórios
- **Painel** de uso dos limites e das horas.
- **Registro** de cada criação controlada e **auditoria** das alterações de configuração.
- **Exportação CSV** de limites, horas por chamado, criações e auditoria.
- Aba **GLPI SAS** na entidade, com os limites e as horas que valem para ela.

## Configuração e direitos

- **Direito nativo** "GLPI SAS" no perfil: ver ou gerenciar.
- **Menu próprio** no topo do GLPI.
- Configuração geral:
  - percentual de alerta e aviso ao chegar perto do limite;
  - perfis isentos;
  - mensagem de bloqueio;
  - itens por página.

Se existirem as tabelas do antigo "controleadministrativo", a instalação migra os dados dele.

---

## Download e instalação

1. Baixe o arquivo `glpisas-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/glpisas/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/glpisas
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install glpisas -u <usuário administrador>
   php bin/console plugin:activate glpisas
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/glpisas` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install glpisas -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).