# CHANGELOG

## V0.0.3
> 2018-01-05

**Features 新特性**
- 支持心跳检测，关闭不活跃连接
- Work进程状态显示通过消息队列与Master进程通信

## V0.0.2
> 2017-03-28

**Features 新特性**
- 增加定时器

## V0.0.1
> 2017-02-16

**Features 新特性**
- 支持Libevent事件驱动
- 支持restart/reload/status指令
- 支持text数据交换协议，解决半包/粘包
- 支持子进程信号处理句柄

**Optimizations 功能优化**
- 子进程意外退出重启

**Bug Fixes 问题修复**
- 客户端断开连接的错误处理
- 解决终端输出样式问题；启动画面调整


*© 2018 RunningMan*
