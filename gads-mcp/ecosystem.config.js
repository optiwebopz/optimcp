// File: /gads-mcp/ecosystem.config.js
// OptiMCP Google Ads MCP — PM2 Config
// Version: 1.0.0

module.exports = {
    apps: [{
        name              : 'gads-mcp',
        script            : 'server.js',
        cwd               : '/root/gads-mcp',
        instances         : 1,
        autorestart       : true,
        watch             : false,
        max_memory_restart: '200M',
        env_file          : '/root/gads-mcp/.env',
        out_file          : '/var/log/gads-mcp/pm2-out.log',
        error_file        : '/var/log/gads-mcp/pm2-err.log',
        merge_logs        : true,
        log_date_format   : 'YYYY-MM-DD HH:mm:ss',
        kill_timeout      : 5000,
        restart_delay     : 2000,
        max_restarts      : 10,
        min_uptime        : '10s',
    }]
};
